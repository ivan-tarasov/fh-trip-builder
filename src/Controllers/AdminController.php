<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use DateTimeImmutable;
use Exception;
use Throwable;
use TripBuilder\Admin;
use TripBuilder\BookingActor;
use TripBuilder\BookingEvent;
use TripBuilder\BookingStatus;
use TripBuilder\Csrf;
use TripBuilder\Helper;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\RateLimit;
use TripBuilder\PanelSetting;
use TripBuilder\Repository\ArticleCategoryRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Repository\BookingEventRepository;
use TripBuilder\Repository\BookingPassengerRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Repository\DashboardRepository;
use TripBuilder\Repository\ScheduleRunRepository;
use TripBuilder\Repository\SettingsRepository;
use TripBuilder\Repository\SubscriberRepository;
use TripBuilder\Schedule;
use TripBuilder\Settings;
use TripBuilder\View\BookingPresenter;
use TripBuilder\View\Markdown;
use TripBuilder\View\TwigRenderer;
use Twig\Error\Error;

/**
 * The way in, and the way out.
 *
 * One password, one session flag, no accounts table -- see `Admin` for why that
 * is the honest size of this rather than a corner cut (A3.2, #100).
 *
 * Everything under `/admin` goes through `guard()`, so a page added here is
 * gated by being here. That is deliberate: a panel where each action remembers
 * to check for itself is a panel where one of them eventually does not.
 *
 * **`render()` and not `renderPage()`, throughout.** `renderPage()` adds the
 * breadcrumb trail and the footer's stats -- a flights count and a query
 * counter -- which are the public layout's furniture. The panel draws its own
 * document and reads none of them, so asking would be a query per page for
 * something nothing prints (A3.5, #230).
 *
 * @phpstan-import-type BookingRow from BookingRepository
 * @phpstan-import-type Presented from BookingPresenter
 * @phpstan-import-type SettingChangeRow from SettingsRepository
 */
class AdminController extends AbstractController
{
    /**
     * What a wrong password is told.
     *
     * One message for every way of being wrong, and it names neither the
     * password nor whether one is configured. There is a single account, so
     * "no such user" and "wrong password" would be the same sentence anyway --
     * but a panel that says "no password is set on this server" is telling a
     * stranger something worth knowing.
     */
    private const string REFUSED = 'That is not the password.';

    /** A slug is lower case, digits and hyphens, like every other one here. */
    private const string SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /** Bookings to a page. Enough to scan, few enough to read. */
    private const int PER_PAGE = 25;

    /**
     * The dashboard.
     *
     * An operations page and not a business one, which was measured rather than
     * chosen -- `DashboardRepository` opens with the counting that decided it
     * (A3.6, #231).
     *
     * @throws Exception|Error
     */
    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }

        $dashboard = new DashboardRepository($this->connection());
        $schedule = $this->schedule();

        echo new TwigRenderer()->render('admin/overview.html.twig', [
            'states' => $dashboard->states($schedule['health']),
            'counts' => $dashboard->counts(),
            'schedule' => $dashboard->schedule($schedule['health'], $schedule['tasks']),
            'content' => $dashboard->content(),
            'searches' => $dashboard->topSearches(5),
            'rate_trend' => $dashboard->rateTrend(),
            // What the research calls freshness transparency, and what this
            // page needs because none of it is cached: every figure was read
            // when the page was drawn, and saying so is what lets somebody
            // trust a green tile.
            'read_at' => date('j M, H:i'),
        ]);
    }

    /**
     * How the scheduled commands are doing, and what they are.
     *
     * `Schedule` already knows how to age a run against its own cron, and
     * `HealthController` already asks it this way -- a second opinion here
     * would be a second thing to keep in step.
     *
     * @return array{health: array<string, array{age: string, stale: bool}>, tasks: list<array{command: string, cron: \TripBuilder\Cron}>}
     */
    private function schedule(): array
    {
        try {
            $schedule = Schedule::fromConfig(Helper::getRootDir() . '/config/noah/schedule.php');

            return [
                'health' => $schedule->health(
                    new DateTimeImmutable(),
                    new ScheduleRunRepository($this->connection())->all(),
                ),
                'tasks' => $schedule->tasks(),
            ];
        } catch (Throwable) {
            // A dashboard that 500s because it could not read a config file
            // would be reporting on itself. The strip says "unknown".
            return ['health' => [], 'tasks' => []];
        }
    }

    /**
     * Every category, and the articles filed under each.
     *
     * Also takes the small POSTs the list itself makes -- move up, move down,
     * show, hide. They are forms rather than links because each one changes
     * something, and they land back here so a refresh does not repeat the last
     * one (A3.3, #101).
     *
     * @throws Exception|Error
     */
    public function content(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->act();

            return;
        }

        $articles = new ArticleRepository($this->connection())->forPanel();
        $grouped = [];

        foreach ($articles as $article) {
            $grouped[$article['category']][] = $article;
        }

        echo new TwigRenderer()->render('admin/content.html.twig', [
            'idle_minutes' => Admin::IDLE_MINUTES,
            'categories' => new ArticleCategoryRepository($this->connection())->forPanel(),
            'articles' => $grouped,
            // Articles whose category names no row. `all()` explains why an
            // orphan is kept rather than dropped; here it has to be visible,
            // because the panel is where it gets fixed.
            'orphans' => $this->orphans($grouped),
        ]);
    }

    /**
     * One article's form, and the saving of it.
     *
     * @throws Exception|Error
     */
    public function article(): void
    {
        if (!$this->guard()) {
            return;
        }

        $articles = new ArticleRepository($this->connection());
        $categories = new ArticleCategoryRepository($this->connection());
        $slug = $this->slugFromPath();

        if ($this->request->isPost()) {
            $this->saveArticle($articles, $categories, $slug);

            return;
        }

        // A slug in the path that names nothing is a link to an article
        // somebody has since deleted, not an invitation to create one under
        // that name -- so it is a 404 and not an empty form.
        $article = $slug === null ? null : $articles->forEditing($slug);

        if ($slug !== null && $article === null) {
            $this->notFound();

            return;
        }

        $this->articleForm($article, $categories);
    }

    /**
     * One category's form, and the saving of it.
     *
     * @throws Exception|Error
     */
    public function category(): void
    {
        if (!$this->guard()) {
            return;
        }

        $categories = new ArticleCategoryRepository($this->connection());
        $slug = $this->slugFromPath();

        if ($this->request->isPost()) {
            $this->saveCategory($categories, $slug);

            return;
        }

        $category = $slug === null ? null : $categories->forEditing($slug);

        if ($slug !== null && $category === null) {
            $this->notFound();

            return;
        }

        $this->categoryForm($category);
    }

    /**
     * Every booking, newest first, or the ones a search matches.
     *
     * **What the list shows is a decision, and it has been taken twice.** The
     * first time it left every name off: `bookings` holds an email, a phone
     * number, a name, a date of birth and a gender -- PIPEDA scope, which is
     * why `db:prune` sweeps it at all -- and a list is for finding the right
     * booking, not for reading people.
     *
     * The travellers are on it now because finding the right booking is
     * usually being done by name. Somebody rings up and says who they are;
     * nobody rings up and quotes a database id. A list that cannot be scanned
     * for the name on the phone is one an operator opens ten bookings from,
     * and ten pages of contact details is worse than one column of names.
     *
     * So: names yes, and everything else still no. No email, no phone, no date
     * of birth, no card. Those stay on the page for the one booking somebody
     * opened, where looking at them was a deliberate act (A3.8, #233).
     *
     * @throws Exception|Error
     */
    public function bookings(): void
    {
        if (!$this->guard()) {
            return;
        }

        $bookings = new BookingRepository($this->connection());
        // Cut to a length somebody could have typed. A `LIKE` is a scan, and
        // the term is the one thing on this page a stranger would control if
        // the guard above ever failed.
        $term = mb_substr(trim($this->request->query->str('q', '')), 0, 64);
        $page = max(1, (int) $this->request->query->str('page', '1'));
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $term === ''
            ? $bookings->recent(self::PER_PAGE, $offset)
            : $bookings->search($term, self::PER_PAGE, $offset);

        // Names and not just a count, which `countsFor()` would give: the same
        // one query answers both, and the column needs the names.
        $names = $rows === []
            ? []
            : new BookingPassengerRepository($this->connection())
                ->namesFor(array_map(self::bookingId(...), $rows));

        $listed = [];

        foreach ($rows as $row) {
            $listed[] = $this->listed($row, $names[$row['id']] ?? []);
        }

        echo new TwigRenderer()->render('admin/bookings.html.twig', [
            'bookings' => $listed,
            'total' => $term === '' ? $bookings->countAll() : $bookings->countMatching($term),
            'term' => $term,
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ]);
    }

    /**
     * @param BookingRow $row
     */
    private static function bookingId(array $row): int
    {
        return $row['id'];
    }

    /**
     * One booking, in full.
     *
     * Shaped by `BookingPresenter`, which is what the traveller's own booking
     * page uses -- so the itinerary, the money at its stored rate and the state
     * cannot disagree with what the customer is looking at while they are on
     * the phone. Only the *drawing* differs: the search card's styling lives in
     * `main.css`, which the panel does not load, and an operator wants the
     * flight numbers that card keeps behind a click.
     *
     * @throws Exception|Error
     */
    public function booking(): void
    {
        if (!$this->guard()) {
            return;
        }

        $parts = explode('/', trim($this->request->path(), '/'));
        $id = (int) end($parts);

        $bookings = new BookingRepository($this->connection());
        $row = $bookings->find($id);

        if ($row === null) {
            $this->notFound();

            return;
        }

        if ($this->request->isPost()) {
            $this->actOnBooking($bookings, $row);

            return;
        }

        $travellers = new BookingPassengerRepository($this->connection());
        $passengers = $travellers->forBooking($id);
        $booking = new BookingPresenter()->booking($row, $passengers);
        $events = new BookingEventRepository($this->connection());
        $counts = $travellers->bookingCountsFor($passengers);

        echo new TwigRenderer()->render('admin/booking.html.twig', [
            'booking' => $booking,
            // Only what the presenter does not already give. No card number is
            // stored anywhere on this site -- a brand and four digits is all
            // there has ever been, which is what a receipt shows.
            'extra' => [
                'id' => $row['id'],
                // Off the column and not off the presenter, which gives nothing
                // at all for a booking whose flights will not build -- and that
                // is the page whose tab most needs to say which booking it is.
                'reference' => trim($row['reference']),
                'card_last4' => $row['card_last4'],
                // Which browser made it. Not identifying on its own, and it is
                // how every other read of this table finds a booking, so an
                // operator chasing a duplicate can see they came from one
                // person rather than two.
                'session' => $row['session_id'],
                'made' => $row['created'],
                'made_ago' => Helper::elapsed($row['created']),
                'is_cancelled' => BookingStatus::fromRow($row['status']) === BookingStatus::Cancelled,
            ],
            'passengers' => $passengers,
            // How many bookings each traveller appears on, in the order the
            // presenter lists them -- both are mapped from `$passengers`, so
            // position n here is position n there. A lookup instead would need
            // the name split back into the two columns it was joined from.
            'elsewhere' => array_map(
                static fn(array $passenger): int
                    => $counts[BookingPassengerRepository::keyFor($passenger)] ?? 1,
                $passengers,
            ),
            'log' => $events->forBooking($id),
            // When the log itself began, so a booking with no events can say
            // why rather than reading as one nothing ever happened to.
            'log_from' => $events->startedAt(),
        ]);
    }

    /**
     * Cancel a booking, or put a cancelled one back.
     *
     * The two actions the panel has, and they are each other's undo -- which is
     * what makes them safe to offer at all. Nothing here deletes: a cancelled
     * booking is a row the traveller can still see, which is the whole reason
     * `bookings.status` exists.
     *
     * The update names the status it expects to find, so the button cannot
     * cancel a booking somebody cancelled while this page was open, and the log
     * is written only when a row actually moved (A3.8, #233).
     *
     * @param BookingRow $row
     */
    private function actOnBooking(BookingRepository $bookings, array $row): void
    {
        $id = $row['id'];
        $back = '/admin/bookings/' . $id;

        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->bounce($back);

            return;
        }

        $change = match ($this->request->body->str('action')) {
            'cancel' => [
                'to' => BookingStatus::Cancelled,
                'from' => BookingStatus::Confirmed,
                'event' => BookingEvent::Cancelled,
            ],
            'reinstate' => [
                'to' => BookingStatus::Confirmed,
                'from' => BookingStatus::Cancelled,
                'event' => BookingEvent::Reinstated,
            ],
            default => null,
        };

        if ($change !== null && $bookings->setStatus($id, $change['to'], $change['from']) > 0) {
            new BookingEventRepository($this->connection())->record(
                $id,
                $change['event'],
                BookingActor::Operator,
                'from the panel',
            );
        }

        $this->bounce($back);
    }

    /**
     * The fare-alert list, and the removing of one address from it.
     *
     * No detail page, unlike a booking: an address and a subscribe date is
     * everything the row holds, so there is nothing to open one for.
     *
     * @throws Exception|Error
     */
    public function subscribers(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->removeSubscriber();

            return;
        }

        $subscribers = new SubscriberRepository($this->connection());
        $page = max(1, (int) $this->request->query->str('page', '1'));
        $offset = ($page - 1) * self::PER_PAGE;

        echo new TwigRenderer()->render('admin/subscribers.html.twig', [
            'subscribers' => $subscribers->all(self::PER_PAGE, $offset),
            'total' => $subscribers->countAll(),
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ]);
    }

    /**
     * The list's one action: take an address off it.
     *
     * Whichever way E23 (#188) eventually gives a visitor their own way off
     * this list, it belongs here too -- one row, one way to leave it.
     */
    private function removeSubscriber(): void
    {
        if (Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            new SubscriberRepository($this->connection())->remove($this->request->body->int('id'));
        }

        $this->bounce('/admin/subscribers');
    }

    /**
     * The settings a config file no longer has the last word on.
     *
     * @throws Exception|Error
     */
    public function settings(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->postSettings();

            return;
        }

        $this->settingsForm();
    }

    /**
     * One POST, two things it might mean: reset a single field (its own
     * button posts only its own name), or save whatever the form is holding.
     */
    private function postSettings(): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->bounce('/admin/settings');

            return;
        }

        $resetKey = $this->request->body->nullableStr('reset_key');

        if ($resetKey !== null) {
            if (PanelSetting::tryFrom($resetKey) !== null) {
                new SettingsRepository($this->connection())->remove($resetKey);
                Settings::forget();
            }

            $this->bounce('/admin/settings');

            return;
        }

        $errors = $this->saveSettings();

        if ($errors !== []) {
            $this->settingsForm($errors);

            return;
        }

        Settings::forget();
        $this->bounce('/admin/settings');
    }

    /**
     * All twelve fields, validated together and written only if every one of
     * them is fine -- a form half saved is a form that lied about which
     * values are actually in effect.
     *
     * @return array<string, string> the key of each invalid field, and why
     */
    private function saveSettings(): array
    {
        $posted = $this->request->body->raw('settings');
        $posted = is_array($posted) ? $posted : [];

        $errors = [];
        $parsed = [];

        foreach (PanelSetting::cases() as $setting) {
            $raw = $posted[$setting->value] ?? '';
            $value = $setting->parse(is_string($raw) ? $raw : '');
            $error = $setting->invalidBecause($value);

            if ($error !== null) {
                $errors[$setting->value] = $error;

                continue;
            }

            $parsed[$setting->value] = $value;
        }

        if ($errors !== []) {
            return $errors;
        }

        $repository = new SettingsRepository($this->connection());

        foreach ($parsed as $key => $value) {
            $repository->set($key, $value);
        }

        return [];
    }

    /**
     * @param array<string, string> $errors keyed by the field that failed
     * @throws Exception|Error
     */
    private function settingsForm(array $errors = []): void
    {
        /** @var array<string, mixed> $overrides */
        $overrides = new SettingsRepository($this->connection())->all();
        $posted = $errors === [] ? null : $this->request->body->raw('settings');
        $posted = is_array($posted) ? $posted : [];

        $groups = [];

        foreach (PanelSetting::cases() as $setting) {
            $key = $setting->value;
            $raw = $posted[$key] ?? null;

            $groups[$setting->group()][] = [
                'key' => $key,
                'label' => $setting->label(),
                'reason' => $setting->reason(),
                'is_list' => $setting->isList(),
                'overridden' => array_key_exists($key, $overrides),
                'error' => $errors[$key] ?? null,
                // The posted text survives a rejected form; everything else
                // reads the effective value, override or config default.
                'value' => is_string($raw) ? $raw : $setting->format(Settings::get($key)),
            ];
        }

        $history = array_map(
            self::historyLine(...),
            new SettingsRepository($this->connection())->history(20),
        );

        echo new TwigRenderer()->render('admin/settings.html.twig', [
            'groups' => $groups,
            'history' => $history,
        ]);
    }

    /**
     * One row of the change log, with `old_value`/`new_value` decoded and
     * formatted the way the field itself would show them -- not the raw
     * JSON they are stored as.
     *
     * @param SettingChangeRow $change
     * @return array{key: string, from: string, to: string, changed_at: string}
     */
    private static function historyLine(array $change): array
    {
        $setting = PanelSetting::tryFrom($change['setting_key']);

        return [
            'key' => $change['setting_key'],
            'from' => self::historyValue($setting, $change['old_value']),
            'to' => self::historyValue($setting, $change['new_value']),
            'changed_at' => $change['changed_at'],
        ];
    }

    /**
     * `null` reads as "(default)" either side of a change: no override before
     * it, or none after it. A key later dropped from `PanelSetting` still
     * shows its raw JSON rather than vanishing from a log that must not edit
     * itself.
     */
    private static function historyValue(?PanelSetting $setting, ?string $encoded): string
    {
        if ($encoded === null) {
            return '(default)';
        }

        return $setting === null ? $encoded : $setting->format(json_decode($encoded, true));
    }

    /**
     * Markdown in, HTML out, for the pane beside the editor.
     *
     * Rendered on the server by the same converter the help page uses, which
     * is the only way a preview is worth having: a second implementation in
     * the browser would agree with this one right up until it did not, and the
     * whole point of a preview is that it is what will be published.
     *
     * @throws Exception|Error
     */
    public function preview(): void
    {
        if (!Admin::isSignedIn()) {
            http_response_code(HttpStatus::Forbidden->value);

            return;
        }

        if (!$this->request->isPost() || !Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            http_response_code(HttpStatus::Forbidden->value);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        echo Markdown::toHtml($this->request->body->str('body'));
    }

    /**
     * The sign-in form, and the posting of it.
     *
     * @throws Exception|Error
     */
    public function login(): void
    {
        if (Admin::isSignedIn()) {
            $this->bounce('/admin');

            return;
        }

        if (!$this->request->isPost()) {
            $this->form();

            return;
        }

        // The token first, then the throttle. A 429 that arrived first would
        // answer a different question than it looks like -- whether the token
        // was accepted -- and would do it without spending one. The same order
        // AjaxController::guardFailure() settled on.
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->form(self::REFUSED, HttpStatus::Forbidden);

            return;
        }

        if ($this->isOverLimit(RateLimit::AdminLogin)) {
            $this->form(RateLimit::AdminLogin->refusal(), HttpStatus::TooManyRequests);

            return;
        }

        if (!Admin::verify($this->request->body->str('password'))) {
            $this->form(self::REFUSED, HttpStatus::Unauthorized);

            return;
        }

        Admin::signIn();
        $this->bounce('/admin');
    }

    /**
     * Sign out, on a POST.
     *
     * A link would do it too, and that is the reason it is a form: a GET that
     * changes something can be fired by any image tag on any page, and being
     * signed out by one is a small nuisance that says the panel is not careful.
     *
     * @throws Exception|Error
     */
    public function logout(): void
    {
        if ($this->request->isPost() && Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Admin::signOut();
        }

        $this->bounce('/admin/login');
    }

    /**
     * The list's own buttons: move, show, hide.
     *
     * One handler and one address, so the list has four small forms rather
     * than four routes. Everything ends in a redirect back to the list, which
     * is what stops a refresh repeating the last move.
     */
    private function act(): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->bounce('/admin/content');

            return;
        }

        $slug = $this->request->body->str('slug');
        $kind = $this->request->body->str('kind');
        $action = $this->request->body->str('action');

        if (preg_match(self::SLUG, $slug) !== 1 || !in_array($kind, ['article', 'category'], true)) {
            $this->bounce('/admin/content');

            return;
        }

        $store = $kind === 'article'
            ? new ArticleRepository($this->connection())
            : new ArticleCategoryRepository($this->connection());

        $current = $kind === 'article'
            ? $store->forEditing($slug)
            : $store->forEditing($slug);

        if ($current !== null) {
            match ($action) {
                'show' => $store->setEnabled($slug, true),
                'hide' => $store->setEnabled($slug, false),
                // A swap with the neighbour rather than a step. Positions are
                // spaced 10, 20, 30 in the seeded data, so adding one to them
                // moved nothing and the button looked broken.
                'up' => $store->move($slug, -1),
                'down' => $store->move($slug, 1),
                default => null,
            };
        }

        $this->bounce('/admin/content');
    }

    /**
     * Save one article, or draw the form again saying what is wrong.
     */
    private function saveArticle(
        ArticleRepository $articles,
        ArticleCategoryRepository $categories,
        ?string $slug,
    ): void {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->articleForm($this->postedArticle($slug), $categories, 'That form went stale. Try again.');

            return;
        }

        $posted = $this->postedArticle($slug);
        $known = $categories->slugs();

        $error = match (true) {
            preg_match(self::SLUG, $posted['slug']) !== 1
                => 'A slug is lower case letters, digits and hyphens.',
            // Checked here and not only by the select, because the form is one
            // way to reach this and not the only one.
            !in_array($posted['category'], $known, true)
                => 'That category does not exist.',
            trim($posted['title']) === '' => 'An article needs a title.',
            trim($posted['summary']) === '' => 'An article needs a summary.',
            // A new slug that is already taken would overwrite somebody else's
            // article through the upsert rather than failing.
            $slug === null && $articles->forEditing($posted['slug']) !== null
                => 'There is already an article with that slug.',
            default => null,
        };

        if ($error !== null) {
            $this->articleForm($posted, $categories, $error);

            return;
        }

        // `edit()` and not `store()`: this write takes the row away from the
        // files, and the importer will leave it alone from now on (A3.4, #102).
        $articles->edit(
            $posted['slug'],
            [
                'category' => $posted['category'],
                'icon' => $posted['icon'],
                'position' => $posted['position'],
            ],
            [
                'title' => $posted['title'],
                // An empty short name is no short name: the column is nullable
                // and the readers fall back to the title.
                'short' => $posted['short'] === '' ? null : $posted['short'],
                'summary' => $posted['summary'],
                'body' => $posted['body'],
            ],
        );

        // After the write, because `store()` inserts a new row enabled and
        // leaves an existing row's flag alone -- deliberately, so an import
        // cannot re-enable something held back. The panel is where that
        // decision is made, so it makes it here.
        $articles->setEnabled($posted['slug'], $posted['enabled']);

        $this->bounce('/admin/content');
    }

    /**
     * Save one category, or draw the form again saying what is wrong.
     */
    private function saveCategory(ArticleCategoryRepository $categories, ?string $slug): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->categoryForm($this->postedCategory($slug), 'That form went stale. Try again.');

            return;
        }

        $posted = $this->postedCategory($slug);

        $error = match (true) {
            preg_match(self::SLUG, $posted['slug']) !== 1
                => 'A slug is lower case letters, digits and hyphens.',
            trim($posted['title']) === '' => 'A category needs a title.',
            trim($posted['summary']) === '' => 'A category needs a summary.',
            $slug === null && $categories->forEditing($posted['slug']) !== null
                => 'There is already a category with that slug.',
            default => null,
        };

        if ($error !== null) {
            $this->categoryForm($posted, $error);

            return;
        }

        $categories->edit(
            $posted['slug'],
            [
                'icon' => $posted['icon'],
                'accent' => $posted['accent'],
                'position' => $posted['position'],
            ],
            ['title' => $posted['title'], 'summary' => $posted['summary']],
        );

        $categories->setEnabled($posted['slug'], $posted['enabled']);

        $this->bounce('/admin/content');
    }

    /**
     * What was typed, shaped like what was loaded.
     *
     * So a form that is refused comes back holding the words rather than
     * emptied -- losing a paragraph to a mistyped slug is how an editor earns
     * a reputation.
     *
     * @return array{slug: string, title: string, short: ?string, icon: string, category: string, summary: string, body: string, position: int, enabled: bool}
     */
    private function postedArticle(?string $slug): array
    {
        $body = $this->request->body;

        return [
            // An existing article keeps the slug in its address: renaming one
            // would orphan every link to it, and there is nothing here that
            // would move them.
            'slug' => $slug ?? trim($body->str('slug')),
            'title' => trim($body->str('title')),
            'short' => trim($body->str('short')),
            'icon' => trim($body->str('icon')),
            'category' => trim($body->str('category')),
            'summary' => trim($body->str('summary')),
            'body' => $body->str('body'),
            'position' => (int) $body->str('position'),
            'enabled' => $body->str('enabled') !== '',
        ];
    }

    /**
     * @return array{slug: string, title: string, summary: string, icon: string, accent: string, position: int, enabled: bool}
     */
    private function postedCategory(?string $slug): array
    {
        $body = $this->request->body;

        return [
            'slug' => $slug ?? trim($body->str('slug')),
            'title' => trim($body->str('title')),
            'summary' => trim($body->str('summary')),
            'icon' => trim($body->str('icon')),
            'accent' => trim($body->str('accent')),
            'position' => (int) $body->str('position'),
            'enabled' => $body->str('enabled') !== '',
        ];
    }

    /**
     * @param array<string, mixed>|null $article
     * @throws Exception|Error
     */
    private function articleForm(?array $article, ArticleCategoryRepository $categories, ?string $error = null): void
    {
        echo new TwigRenderer()->render('admin/article.html.twig', [
            'article' => $article,
            'categories' => $categories->forPanel(),
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, mixed>|null $category
     * @throws Exception|Error
     */
    private function categoryForm(?array $category, ?string $error = null): void
    {
        echo new TwigRenderer()->render('admin/category.html.twig', [
            'category' => $category,
            'accent' => ArticleCategoryRepository::DEFAULT_ACCENT,
            'error' => $error,
        ]);
    }

    /**
     * The slug in the address, or null where there is none and this is a new
     * one being written.
     */
    private function slugFromPath(): ?string
    {
        $parts = explode('/', trim($this->request->path(), '/'));
        $last = end($parts);

        // `/admin/article` has two segments and names no slug; three is an
        // edit. The pattern in Routes guarantees the shape, so this only has
        // to say which of the two it is.
        return count($parts) > 2 && preg_match(self::SLUG, $last) === 1 ? $last : null;
    }

    /**
     * Articles filed under a category that does not exist.
     *
     * @param array<string, list<array<string, mixed>>> $grouped
     * @return list<array<string, mixed>>
     */
    private function orphans(array $grouped): array
    {
        $known = new ArticleCategoryRepository($this->connection())->slugs();
        $orphans = [];

        foreach ($grouped as $category => $articles) {
            if (!in_array((string) $category, $known, true)) {
                $orphans = [...$orphans, ...$articles];
            }
        }

        return $orphans;
    }

    /**
     * One booking as the list shows it.
     *
     * Through `BookingPresenter`, the same shaper the traveller's own booking
     * page uses, so a reference, a route and a total read identically on both
     * sides -- which matters when the two are being compared down a phone.
     *
     * **A booking whose flights will not build is not dropped.** The presenter
     * returns null for one, because a traveller should not be shown a booking
     * with no flights in it; an operator should, because that row is exactly
     * the one somebody is calling about. So the list falls back to the columns.
     *
     * @param BookingRow $row
     * @param list<string> $names everyone on it, lead first, empty on a booking
     *     made before travellers had rows of their own
     * @return array<string, mixed>
     */
    private function listed(array $row, array $names): array
    {
        // The lead off the booking's own row and not off `$names`, because
        // those two agree on every booking that has both and only the first
        // exists on the ones written before `booking_passengers` did.
        $lead = trim($row['passenger_first'] . ' ' . $row['passenger_last']);
        $party = [
            'lead' => $lead === '' ? ($names[0] ?? '') : $lead,
            // Everyone else, named rather than only counted: an operator
            // looking for the child on a family booking is looking for a name
            // that is not the lead's. A party of one has none.
            'others' => array_slice($names, 1),
        ];

        $travellers = max(1, count($names));
        $shaped = new BookingPresenter()->booking($row, [], $travellers);

        // The date answers when and this answers how long, and an operator
        // scanning the list is asking the second one.
        $made = ['created' => $row['created'], 'made_ago' => Helper::elapsed($row['created'])];

        if ($shaped === null) {
            return [
                ...$party,
                ...$made,
                'id' => $row['id'],
                'reference' => trim($row['reference']),
                'status_label' => $row['status'],
                'is_cancelled' => $row['status'] === 'cancelled',
                'from' => null,
                'to' => null,
                'departs' => null,
                'travellers' => $travellers,
                'price_total' => null,
                'broken' => true,
            ];
        }

        // Off the itinerary itself rather than off `rebook`, which carries a
        // from and a to for building a fresh search link. These are the
        // airports this booking actually flies between.
        $outbound = $shaped['outbound'];

        return [
            ...$party,
            ...$made,
            'id' => $row['id'],
            'reference' => $shaped['reference'],
            'status_label' => $shaped['status_label'],
            'is_cancelled' => $shaped['is_cancelled'],
            'from' => $outbound['depart_code'] ?? null,
            'to' => $outbound['arrive_code'] ?? null,
            'departs' => $shaped['starts_at'],
            'travellers' => $travellers,
            'price_total' => $shaped['price_total'],
            'broken' => false,
        ];
    }

    /**
     * Let a signed-in operator through, or send them to the form.
     *
     * Returns false having already answered, so a caller is one `if` away from
     * being gated.
     */
    protected function guard(): bool
    {
        if (Admin::isSignedIn()) {
            return true;
        }

        $this->bounce('/admin/login');

        return false;
    }

    /**
     * @throws Exception|Error
     */
    private function form(?string $error = null, HttpStatus $status = HttpStatus::Ok): void
    {
        if ($status !== HttpStatus::Ok && !headers_sent()) {
            http_response_code($status->value);
        }

        echo new TwigRenderer()->render('admin/login.html.twig', [
            'error' => $error,
            // So a server with no hash says so on its own sign-in page, where
            // the person who can fix it is standing, rather than refusing a
            // correct password with no explanation.
            'configured' => Admin::isConfigured(),
        ]);
    }
}
