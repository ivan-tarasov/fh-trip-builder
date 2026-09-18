<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use DateTimeImmutable;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use TripBuilder\Admin;
use TripBuilder\AdminEvent;
use TripBuilder\AdminEventResource;
use TripBuilder\BookingActor;
use TripBuilder\BookingEvent;
use TripBuilder\BookingStatus;
use TripBuilder\CabinClass;
use TripBuilder\Cron;
use TripBuilder\Csrf;
use TripBuilder\DocumentType;
use TripBuilder\Env;
use TripBuilder\EnvKey;
use TripBuilder\Flash;
use TripBuilder\FlashTone;
use TripBuilder\Helper;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\RateLimit;
use TripBuilder\Mail\Mailtrap;
use TripBuilder\Money;
use TripBuilder\Noah\Airside\Import as AirsideImport;
use TripBuilder\Noah\Airside\Prune as AirsidePrune;
use TripBuilder\Noah\Alerts\Check as AlertsCheck;
use TripBuilder\Noah\Articles\Import as ArticlesImport;
use TripBuilder\Noah\Currency\Rates as CurrencyRates;
use TripBuilder\Noah\Db\Backup as DatabaseBackup;
use TripBuilder\Noah\Db\Prune as DatabasePrune;
use TripBuilder\Noah\Flights\Cabins as CabinsFlights;
use TripBuilder\Noah\Flights\Cleaning as CleaningFlights;
use TripBuilder\Noah\Flights\Generate as GenerateFlights;
use TripBuilder\Noah\Flights\Realign as RealignFlights;
use TripBuilder\Noah\Flights\Reprice as RepriceFlights;
use TripBuilder\PanelSetting;
use TripBuilder\RemarkTone;
use TripBuilder\Repository\AdminEventRepository;
use TripBuilder\Repository\ArticleCategoryRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Repository\BookingEventRepository;
use TripBuilder\Repository\BookingPassengerRepository;
use TripBuilder\Repository\BookingRemarkRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Repository\BookingTicketRepository;
use TripBuilder\Repository\CountryRepository;
use TripBuilder\Repository\DashboardRepository;
use TripBuilder\Repository\RouteWatchRepository;
use TripBuilder\Repository\ScheduledJobRepository;
use TripBuilder\Repository\ScheduleRunRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\Repository\SettingsRepository;
use TripBuilder\Repository\SubscriberRepository;
use TripBuilder\Schedule;
use TripBuilder\Settings;
use TripBuilder\TicketStatus;
use TripBuilder\View\BookingPresenter;
use TripBuilder\View\ItineraryPresenter;
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

    /** Results per kind in the command palette -- a palette, not a list page. */
    private const int SEARCH_LIMIT = 5;

    /** A remark long enough for a real note and short enough to still read as one. */
    private const int REMARK_MAX_LENGTH = 2000;

    /** Longer than any real ticket or EMD number needs, with room to spare. */
    private const int DOCUMENT_NUMBER_MAX_LENGTH = 20;

    /** How many days back each named range covers. `all` has no entry -- it has no length to name. */
    private const array HERO_RANGE_DAYS = ['1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    /**
     * The range names `searches()` and `subscribersRoutes()` both offer -- a
     * year, not `all`: nothing here needs an unbounded query.
     */
    private const array SEARCH_RANGE_DAYS = ['1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90, '180d' => 180, '365d' => 365];

    /** More than Overview's own top five, still short enough to read in one screen. */
    private const int SEARCHES_RANKING_LIMIT = 20;

    /** Same reasoning as `SEARCHES_RANKING_LIMIT`, for the routes ranked by watch count. */
    private const int TOP_WATCHED_ROUTES_LIMIT = 10;

    /**
     * What a scheduled job is allowed to run (G19, #377), and the class that
     * answers for its own description and arguments -- one map rather than a
     * name list plus a second lookup kept in step with it by hand.
     *
     * Not the full 16 `noah` commands -- `db:clear` wipes tables, `app:install`
     * and `db:migrate` are one-time operations a schedule would repeat forever,
     * `schedule:run` is the scheduler itself, and `admin:password` is an
     * interactive prompt that does nothing when run unattended. Picking from
     * "every registered command" would put a destructive one in the same
     * dropdown as a currency refresh.
     *
     * @var array<string, class-string<Command>>
     */
    private const array SCHEDULABLE_COMMAND_CLASSES = [
        CurrencyRates::NAME => CurrencyRates::class,
        DatabasePrune::NAME => DatabasePrune::class,
        DatabaseBackup::NAME => DatabaseBackup::class,
        GenerateFlights::NAME => GenerateFlights::class,
        CleaningFlights::NAME => CleaningFlights::class,
        RepriceFlights::NAME => RepriceFlights::class,
        CabinsFlights::NAME => CabinsFlights::class,
        RealignFlights::NAME => RealignFlights::class,
        ArticlesImport::NAME => ArticlesImport::class,
        AirsideImport::NAME => AirsideImport::class,
        AirsidePrune::NAME => AirsidePrune::class,
        AlertsCheck::NAME => AlertsCheck::class,
    ];

    /** Flags and values only -- what a real command's arguments look like. */
    private const string ARGUMENTS_PATTERN = '/^[A-Za-z0-9 _.=,-]*$/';

    /**
     * What a category's `accent` can be -- the colours
     * `.help-group__icon--*` actually defines in `main.css`. Not the rail
     * icon's own six; that CSS rule doesn't back this icon family.
     */
    private const array CATEGORY_ACCENTS = ['green', 'blue', 'orange', 'violet', 'pink'];

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

        if ($this->request->isPost()) {
            $this->removeSearchRoute('/admin');

            return;
        }

        $dashboard = new DashboardRepository($this->connection());
        $schedule = $this->scheduleHealth();

        echo new TwigRenderer()->render('admin/overview.html.twig', [
            // "What should I do next" (G5.2, #317), ahead of "is it
            // running": the same four states, but only the ones that
            // are not fine, plus the one signal that already existed per
            // booking but nowhere across all of them.
            'attention' => $dashboard->attention($schedule['health']),
            'states' => $dashboard->states($schedule['health']),
            'counts' => $dashboard->counts(),
            'schedule' => $dashboard->schedule($schedule['health'], $schedule['tasks']),
            'content' => $dashboard->content(),
            'searches' => $dashboard->topSearches(5),
            // "Did I already fix this" (G5.1, #316): content edits and a
            // removed fare-alert address, the two things this panel wrote
            // with nothing to show for it until now.
            'activity' => array_map(
                self::activityLine(...),
                new AdminEventRepository($this->connection())->recent(5),
            ),
            // What the research calls freshness transparency, and what this
            // page needs because none of it is cached: every figure was read
            // when the page was drawn, and saying so is what lets somebody
            // trust a green tile. A real object, not a pre-formatted string
            // -- the template needs both the UTC reading and the instant
            // itself, to show a visitor's own local time (G12, #360).
            'read_at' => new DateTimeImmutable(),
        ]);
    }

    /**
     * "Most searched"'s own undo -- a route that should never have reached
     * the count it did (no rate limit existed on `search_count` until G13,
     * #362) stays inflated forever unless an operator can clear it.
     *
     * Posted to from both Overview's own list and the full `searches()`
     * page (G16, #369), so it bounces back to whichever one called it
     * rather than always landing on `/admin`.
     */
    private function removeSearchRoute(string $bounceTo): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Flash::set('That form went stale. Try again.', FlashTone::Error);
            $this->bounce($bounceTo);

            return;
        }

        $from = strtoupper($this->request->body->str('from'));
        $to = strtoupper($this->request->body->str('to'));
        $removed = new SearchRepository($this->connection())->removeRoute($from, $to);

        if ($removed > 0) {
            new AdminEventRepository($this->connection())->record(
                AdminEventResource::Search,
                $from . '-' . $to,
                AdminEvent::Removed,
                $from . ' → ' . $to,
            );
        }

        Flash::set('Removed ' . $from . ' → ' . $to . ' from Most searched.');
        $this->bounce($bounceTo);
    }

    /**
     * The full ranking and a real total-volume trend, where Overview only
     * has room for the top five.
     *
     * `search.search_count` is a lifetime total per route with no per-day
     * history, so the range only ever changes the trend and its headline
     * total -- both real, from `search_daily_counts` (G7.1, #332). The
     * ranking below stays lifetime, the same numbers Overview shows,
     * because filtering it by the range would answer a question this data
     * cannot honestly answer (G16, #369).
     */
    public function searches(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->removeSearchRoute('/admin/searches');

            return;
        }

        $range = $this->request->query->str('range', '7d');
        $range = array_key_exists($range, self::SEARCH_RANGE_DAYS) ? $range : '7d';

        // Exclusive: `[$from, $to)` never needs a day's worth of `<=` fuss.
        $to = new DateTimeImmutable('tomorrow');
        $from = $to->modify('-' . self::SEARCH_RANGE_DAYS[$range] . ' days');

        $searches = new SearchRepository($this->connection());

        echo new TwigRenderer()->render('admin/searches.html.twig', [
            'range' => $range,
            'range_label' => match ($range) {
                '1d' => 'today',
                '7d' => 'in the last 7 days',
                '30d' => 'in the last 30 days',
                '90d' => 'in the last 90 days',
                '180d' => 'in the last 6 months',
                '365d' => 'in the last year',
            },
            'total' => number_format($searches->total($from, $to)),
            'daily' => $searches->dailyCounts($from, $to),
            'top' => new DashboardRepository($this->connection())->topSearches(self::SEARCHES_RANKING_LIMIT),
        ]);
    }

    /**
     * How the scheduled commands are doing, and what they are.
     *
     * `Schedule` already knows how to age a run against its own cron, and
     * `HealthController` already asks it this way -- a second opinion here
     * would be a second thing to keep in step.
     *
     * Named apart from the public `schedule()` route action below it, which
     * answers a different question (the schedule itself, edited) rather than
     * this one (is it healthy, read-only, for the dashboard).
     *
     * @return array{health: array<string, array{age: string, stale: bool}>, tasks: list<array{command: string, cron: Cron}>}
     */
    private function scheduleHealth(): array
    {
        try {
            $schedule = Schedule::fromRows(new ScheduledJobRepository($this->connection())->allEnabled());

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
     * The live schedule -- every job, its status, and a history link. Also
     * takes the list's own small POSTs: enable, disable, remove -- the same
     * shape as `content()`'s show/hide/move.
     */
    public function schedule(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->scheduleAct();

            return;
        }

        echo new TwigRenderer()->render('admin/schedule.html.twig', [
            'jobs' => $this->scheduleJobRows(new ScheduledJobRepository($this->connection())->all()),
        ]);
    }

    /**
     * One job's form, and the saving of it. Mirrors `category()`: no id in
     * the path is a new job, an id is that job.
     *
     * @throws Exception|Error
     */
    public function scheduleJob(): void
    {
        if (!$this->guard()) {
            return;
        }

        $jobs = new ScheduledJobRepository($this->connection());
        $id = $this->numericIdFromPath();

        if ($this->request->isPost()) {
            $this->saveScheduleJob($jobs, $id);

            return;
        }

        $job = $id === null ? null : $jobs->find($id);

        if ($id !== null && $job === null) {
            $this->notFound();

            return;
        }

        $split = self::splitCommand($job['command'] ?? '');

        $this->scheduleJobForm($id, [
            'command_base' => $split['base'],
            'arguments' => $split['arguments'],
            'minute' => $job['minute'] ?? Cron::EVERY,
            'hour' => $job['hour'] ?? Cron::EVERY,
            'day' => $job['day'] ?? Cron::EVERY,
            'month' => $job['month'] ?? Cron::EVERY,
            'weekday' => $job['weekday'] ?? Cron::EVERY,
            'enabled' => $job['enabled'] ?? true,
        ]);
    }

    /**
     * One command's real run history, newest first.
     *
     * Addressed by the job's own id rather than its command spelled out in
     * the query string -- `?command=db%3Aprune%20--force` is what a command
     * with a flag on it turned into, the same reasoning the job editor
     * itself is addressed by id and not by name.
     *
     * @throws Exception|Error
     */
    public function scheduleHistory(): void
    {
        if (!$this->guard()) {
            return;
        }

        $id = $this->numericIdFromPath();
        $job = $id === null ? null : new ScheduledJobRepository($this->connection())->find($id);

        if ($job === null) {
            $this->notFound();

            return;
        }

        $runs = new ScheduleRunRepository($this->connection())->historyFor($job['command'], 50);
        $base = self::splitCommand($job['command'])['base'];

        echo new TwigRenderer()->render('admin/schedule-history.html.twig', [
            'command' => $job['command'],
            'commandDescription' => self::scheduledCommandHelp()[$base]['description'] ?? null,
            'runs' => array_map(static function (array $row): array {
                $started = new DateTimeImmutable($row['started_at']);
                $finished = $row['finished_at'] === null ? null : new DateTimeImmutable($row['finished_at']);

                return [
                    'started_at' => $started,
                    'finished_at' => $finished,
                    'exit_code' => $row['exit_code'],
                    'duration' => self::runDuration($started, $finished),
                ];
            }, $runs),
        ]);
    }

    /**
     * How long a run took, or null while it has no finish to measure against
     * -- still running, or killed before it could report (the history page's
     * own table already says which).
     *
     * `format('U.u')` rather than `getTimestamp()` -- most of what runs here
     * (`alerts:check`, `currency:rates`) finishes inside the same
     * wall-clock second it started, and whole seconds alone would call
     * every one of those "0s".
     */
    public static function runDuration(DateTimeImmutable $started, ?DateTimeImmutable $finished): ?string
    {
        if ($finished === null) {
            return null;
        }

        $elapsedMs = (int) round((((float) $finished->format('U.u')) - ((float) $started->format('U.u'))) * 1000);

        if ($elapsedMs < 1000) {
            return $elapsedMs . 'ms';
        }

        $seconds = intdiv($elapsedMs, 1000);

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $rest === 0 ? $minutes . 'm' : sprintf('%dm %ds', $minutes, $rest);
    }

    /**
     * Every job as the list page shows it: its own cron read as an
     * expression, plus the same age/exit/stale reading Overview's table
     * gives -- built straight from `Schedule::health()` rather than through
     * `DashboardRepository::schedule()`, which only ever sees the enabled
     * jobs `Run.php` would and this page needs the disabled ones too.
     *
     * @param list<array{id: int, command: string, minute: string, hour: string, day: string, month: string, weekday: string, enabled: bool}> $rows
     * @return list<array{id: int, command: string, cron: string, enabled: bool, age: string, exit: ?int, stale: bool, last_ran: ?string}>
     */
    private function scheduleJobRows(array $rows): array
    {
        $schedule = Schedule::fromRows($rows);
        $records = new ScheduleRunRepository($this->connection())->all();
        $health = $schedule->health(new DateTimeImmutable(), $records);

        $expressions = [];

        foreach ($schedule->tasks() as $task) {
            $expressions[$task['command']] = $task['cron']->expression();
        }

        $built = [];

        foreach ($rows as $job) {
            $seen = $health[$job['command']] ?? ['age' => 'never', 'stale' => true];

            $built[] = [
                'id' => $job['id'],
                'command' => $job['command'],
                'cron' => $expressions[$job['command']],
                'enabled' => $job['enabled'],
                'age' => $seen['age'],
                'exit' => isset($records[$job['command']]) ? $records[$job['command']]['last_exit'] : null,
                'stale' => $seen['stale'],
                // When it last *started*, not `health()`'s own "age" (last
                // *success*) -- a job failing every night should still say
                // when it was last attempted, which Status already reads as
                // failing.
                'last_ran' => $records[$job['command']]['last_run_at'] ?? null,
            ];
        }

        return $built;
    }

    /**
     * The list's own small POSTs: enable, disable, remove.
     */
    private function scheduleAct(): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Flash::set('That form went stale. Try again.', FlashTone::Error);
            $this->bounce('/admin/schedule');

            return;
        }

        $id = $this->request->body->int('id');
        $action = $this->request->body->str('action');
        $jobs = new ScheduledJobRepository($this->connection());
        $job = $jobs->find($id);

        if ($job === null || !in_array($action, ['enable', 'disable', 'remove', 'run'], true)) {
            Flash::set('Nothing changed. That was not a real job.', FlashTone::Error);
            $this->bounce('/admin/schedule');

            return;
        }

        if ($action === 'run') {
            $started = $this->runScheduledJobNow($job['command']);
            Flash::set(
                $started
                    ? 'Started ' . $job['command'] . '. Check back in a moment.'
                    : 'Could not start it -- this server does not allow running commands from a web request.',
                $started ? FlashTone::Success : FlashTone::Error,
            );
            $this->bounce('/admin/schedule');

            return;
        }

        match ($action) {
            'enable' => $jobs->setEnabled($id, true),
            'disable' => $jobs->setEnabled($id, false),
            'remove' => $jobs->remove($id),
        };

        new AdminEventRepository($this->connection())->record(
            AdminEventResource::Schedule,
            $job['command'],
            $action === 'remove' ? AdminEvent::Removed : AdminEvent::Edited,
            $job['command'],
        );

        Flash::set(match ($action) {
            'enable' => 'Job enabled.',
            'disable' => 'Job disabled.',
            'remove' => 'Job removed.',
        });
        $this->bounce('/admin/schedule');
    }

    private function saveScheduleJob(ScheduledJobRepository $jobs, ?int $id): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->scheduleJobForm($id, $this->postedScheduleJob(), 'That form went stale. Try again.');

            return;
        }

        $posted = $this->postedScheduleJob();
        $command = $posted['arguments'] === ''
            ? $posted['command_base']
            : $posted['command_base'] . ' ' . $posted['arguments'];

        $error = match (true) {
            !array_key_exists($posted['command_base'], self::SCHEDULABLE_COMMAND_CLASSES)
                => 'Pick one of the commands this can actually run.',
            $posted['arguments'] !== '' && preg_match(self::ARGUMENTS_PATTERN, $posted['arguments']) !== 1
                => 'Arguments can only use letters, digits, spaces, and - _ . = ,',
            $jobs->commandExists($command, $id)
                => 'There is already a job for that exact command and arguments.',
            default => null,
        };

        if ($error === null) {
            try {
                Cron::fromFields([
                    Cron::MINUTE => $posted['minute'],
                    Cron::HOUR => $posted['hour'],
                    Cron::DAY => $posted['day'],
                    Cron::MONTH => $posted['month'],
                    Cron::WEEKDAY => $posted['weekday'],
                ], $command);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error !== null) {
            $this->scheduleJobForm($id, $posted, $error);

            return;
        }

        if ($id === null) {
            $jobs->create(
                $command,
                $posted['minute'],
                $posted['hour'],
                $posted['day'],
                $posted['month'],
                $posted['weekday'],
                $posted['enabled'],
            );
        } else {
            $jobs->update(
                $id,
                $command,
                $posted['minute'],
                $posted['hour'],
                $posted['day'],
                $posted['month'],
                $posted['weekday'],
                $posted['enabled'],
            );
        }

        new AdminEventRepository($this->connection())
            ->record(AdminEventResource::Schedule, $command, AdminEvent::Edited, $command);

        Flash::set($id === null ? 'Job created.' : 'Job saved.');
        $this->bounce('/admin/schedule');
    }

    /**
     * @return array{command_base: string, arguments: string, minute: string, hour: string, day: string, month: string, weekday: string, enabled: bool}
     */
    private function postedScheduleJob(): array
    {
        $body = $this->request->body;

        return [
            'command_base' => $body->str('command_base'),
            'arguments' => trim($body->str('arguments')),
            'minute' => trim($body->str('minute')),
            'hour' => trim($body->str('hour')),
            'day' => trim($body->str('day')),
            'month' => trim($body->str('month')),
            'weekday' => trim($body->str('weekday')),
            'enabled' => $body->str('enabled') !== '',
        ];
    }

    /**
     * @param array{command_base: string, arguments: string, minute: string, hour: string, day: string, month: string, weekday: string, enabled: bool} $job
     * @throws Exception|Error
     */
    private function scheduleJobForm(?int $id, array $job, ?string $error = null): void
    {
        echo new TwigRenderer()->render('admin/schedule-job.html.twig', [
            'id' => $id,
            'job' => $job,
            'commands' => array_keys(self::SCHEDULABLE_COMMAND_CLASSES),
            'commandHelp' => self::scheduledCommandHelp(),
            'error' => $error,
        ]);
    }

    /**
     * Every schedulable command's own description and arguments, straight
     * from its Symfony `Command` definition -- the same text `noah <command>
     * --help` would print, rather than a second copy of it kept in step by
     * hand. Instantiating a command is safe and free of side effects: DB
     * connections, `.env` loading and console output all happen in
     * `AbstractCommand::initialize()`, which only `Command::run()` calls, not
     * the constructor.
     *
     * @return array<string, array{
     *     description: string,
     *     arguments: list<array{name: string, required: bool, description: string}>,
     *     options: list<array{flag: string, takesValue: bool, description: string}>,
     * }>
     */
    private static function scheduledCommandHelp(): array
    {
        $help = [];

        foreach (self::SCHEDULABLE_COMMAND_CLASSES as $name => $class) {
            $command = new $class();
            $definition = $command->getDefinition();

            $help[$name] = [
                'description' => (string) $command->getDescription(),
                'arguments' => array_map(
                    static fn(InputArgument $argument): array => [
                        'name' => $argument->getName(),
                        'required' => $argument->isRequired(),
                        'description' => $argument->getDescription(),
                    ],
                    array_values($definition->getArguments()),
                ),
                'options' => array_map(
                    static fn(InputOption $option): array => [
                        'flag' => '--' . $option->getName(),
                        'takesValue' => $option->acceptValue(),
                        'description' => $option->getDescription(),
                    ],
                    array_values($definition->getOptions()),
                ),
            ];
        }

        return $help;
    }

    /**
     * @return array{base: string, arguments: string}
     */
    private static function splitCommand(string $command): array
    {
        $parts = explode(' ', $command, 2);

        return ['base' => $parts[0], 'arguments' => $parts[1] ?? ''];
    }

    /**
     * `/admin/schedule`'s "Run now" -- the exact command a `scheduled_jobs`
     * row already runs unattended, just sooner. Detached (`&`, output
     * discarded) so a slow job (`flights:add` and `flights:reprice` can take
     * minutes) does not hold this request open; `noah schedule:run <command>`
     * records start/finish in `schedule_runs`/`schedule_run_history` itself,
     * the same as a real tick, so "Last ran" and History pick it up once it
     * is done.
     *
     * Returns false without starting anything when this PHP cannot run a
     * process at all -- some hardened production pools disable `exec()` on
     * the web SAPI on purpose, and that is worth a clear message rather than
     * a silent no-op.
     */
    private function runScheduledJobNow(string $command): bool
    {
        $noah = dirname(__DIR__, 2) . '/noah';
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (in_array('exec', $disabled, true) || !is_executable($noah)) {
            return false;
        }

        // `noah`'s own `#!/usr/bin/env php` picks up whatever `php` sits
        // first on this process's inherited PATH, which is not necessarily
        // the one actually running this request -- a real risk on a host
        // with more than one PHP install. `self::cliPhpBinary()` names the
        // exact interpreter when it safely can; null falls back to the
        // shebang's own PATH lookup, the same resolution the existing cron
        // entry already depends on.
        $interpreter = self::cliPhpBinary();

        exec(sprintf(
            '%s%s schedule:run %s > /dev/null 2>&1 &',
            $interpreter === null ? '' : escapeshellarg($interpreter) . ' ',
            escapeshellarg($noah),
            escapeshellarg($command),
        ));

        return true;
    }

    /**
     * A real PHP CLI binary to run `noah` with, or null to trust its own
     * shebang instead.
     *
     * `PHP_BINARY` is the actual CLI interpreter under `php -S`, but under
     * an embedded SAPI it names the web server's own binary instead --
     * `apache2handler` under mod_php (this app's own local setup, per its
     * README) and `fpm-fcgi` under php-fpm are both real cases, and neither
     * understands `noah` as an argument -- one is Apache, the other is a
     * pool master, and both simply fail to run it, which is why "Run now"
     * looked like it worked (a flash message; nothing checked what actually
     * happened) but never wrote a row.
     *
     * `PHP_BINDIR` is the fix: the "bin" directory of the exact PHP build
     * serving this request, fixed at compile time and present under every
     * SAPI, not only `cli`/`cli-server` -- almost every real PHP install
     * (Homebrew, the Linux distro packages, MAMP/ServBay's own bundles)
     * ships a `php` CLI binary there alongside whatever SAPI module or FPM
     * binary is actually running. Verified rather than assumed either way:
     * a real PHP CLI answers `-v` with "... (cli) ...", which neither a web
     * server nor an FPM master does.
     */
    private static function cliPhpBinary(): ?string
    {
        foreach ([PHP_BINDIR . '/php', PHP_BINARY] as $candidate) {
            if (!is_executable($candidate)) {
                continue;
            }

            $lines = [];
            exec(escapeshellarg($candidate) . ' -v 2>&1', $lines, $exit);

            if ($exit === 0 && isset($lines[0]) && str_contains($lines[0], '(cli)')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The id in the address, shared by `/admin/schedule/job(/{id})?` (null
     * means a new job being written) and `/admin/schedule/history/{id}`.
     * One path segment deeper than `slugFromPath()`'s own
     * `/admin/category(/{slug})?`.
     */
    private function numericIdFromPath(): ?int
    {
        $parts = explode('/', trim($this->request->path(), '/'));
        $last = end($parts);

        return count($parts) > 3 && ctype_digit($last) ? (int) $last : null;
    }

    /**
     * The command palette's own endpoint -- a booking, a subscriber, a
     * help-content article, from wherever in the panel somebody is rather
     * than from the one list page each thing happens to live on (G4.1,
     * #312).
     *
     * `Admin::isSignedIn()` and a bare 403, not `guard()`'s redirect: this
     * is answered to a script, not navigated to, and a script does not
     * want a login page back in place of JSON. No CSRF check either -- a
     * read costs nothing to repeat, which is the whole reason that check
     * exists for the actions that do.
     */
    public function search(): void
    {
        header('Content-type: application/json; charset=utf-8');

        if (!Admin::isSignedIn()) {
            http_response_code(HttpStatus::Forbidden->value);
            echo json_encode(['results' => []]);

            return;
        }

        $term = mb_substr(trim($this->request->query->str('q', '')), 0, 64);

        if ($term === '') {
            echo json_encode(['results' => []]);

            return;
        }

        $results = [];

        foreach (new BookingRepository($this->connection())->filtered($term, null, null, null, false, self::SEARCH_LIMIT) as $row) {
            $results[] = [
                'type' => 'Booking',
                'label' => trim($row['reference']) ?: ('Booking ' . $row['id']),
                'meta' => trim($row['passenger_first'] . ' ' . $row['passenger_last']),
                'url' => '/admin/bookings/' . $row['id'],
            ];
        }

        foreach (new SubscriberRepository($this->connection())->search($term, self::SEARCH_LIMIT) as $row) {
            $results[] = [
                'type' => 'Subscriber',
                'label' => $row['email'],
                'meta' => null,
                'url' => '/admin/subscribers',
            ];
        }

        // A handful of articles at most on a site this size -- filtered in
        // PHP rather than a second SQL search, the same call `content()`
        // already makes for the panel's own article list.
        $needle = mb_strtolower($term);
        $matched = 0;

        foreach (new ArticleRepository($this->connection())->forPanel() as $article) {
            if ($matched >= self::SEARCH_LIMIT) {
                break;
            }

            // The title first -- a reader typing a couple of words is
            // usually naming the thing, not quoting its summary -- but a
            // summary is what a reader searches when they remember the
            // topic and not the headline it was given.
            if (
                !str_contains(mb_strtolower($article['title']), $needle)
                && !str_contains(mb_strtolower($article['summary']), $needle)
            ) {
                continue;
            }

            $results[] = [
                'type' => 'Help content',
                'label' => $article['title'],
                'meta' => $article['category'],
                'url' => '/admin/article/' . $article['slug'],
            ];
            $matched++;
        }

        echo json_encode(['results' => $results]);
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
        $status = BookingStatus::tryFrom($this->request->query->str('status', ''));
        $from = self::validDate($this->request->query->str('from', ''));
        $to = self::validDate($this->request->query->str('to', ''));
        $missingTicket = $this->request->query->str('missing_ticket', '') === '1';
        $page = max(1, (int) $this->request->query->str('page', '1'));
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $bookings->filtered($term, $status, $from, $to, $missingTicket, self::PER_PAGE, $offset);

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

        $range = $this->request->query->str('range', '30d');
        $range = $range === 'all' || array_key_exists($range, self::HERO_RANGE_DAYS) ? $range : '30d';

        echo new TwigRenderer()->render('admin/bookings.html.twig', [
            'bookings' => $listed,
            'total' => $bookings->countFiltered($term, $status, $from, $to, $missingTicket),
            'term' => $term,
            'status' => $status,
            'statuses' => BookingStatus::cases(),
            'from' => $from,
            'to' => $to,
            'missing_ticket' => $missingTicket,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'range' => $range,
            'hero' => $this->hero($bookings, $range),
            // Suppressed once the filter itself is already showing only
            // these -- pointing at a filter the operator is already
            // looking through would be telling them something they can
            // already see (G18, #376).
            'missing_ticket_alert' => $missingTicket
                ? null
                : new DashboardRepository($this->connection())->unticketedBookingsAlert(),
        ]);
    }

    /**
     * A `from`/`to` filter value, or null for anything that is not really a
     * date -- empty, malformed, or a calendar day that does not exist. A
     * filter that silently matched everything for "2026-13-45" would be a
     * filter that lied about what it did, the same reasoning `like()` gives
     * for a raw `%`.
     */
    private static function validDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * The four hero cards: made, cancelled, gross cost, and a
     * search-to-book ratio, each with its own day-by-day trend.
     *
     * @return list<array{
     *     key: string, label: string, display: string, tone: string,
     *     delta: array{pct: float, good: bool}|null, points: array<string, int|float>,
     * }>
     */
    private function hero(BookingRepository $bookings, string $range): array
    {
        $searches = new SearchRepository($this->connection());

        // Exclusive: `[$from, $to)` never needs a day's worth of `<=` fuss.
        $to = new DateTimeImmutable('tomorrow');
        $days = self::HERO_RANGE_DAYS[$range] ?? null;

        // `all` starts a year back rather than from null, so every query
        // below stays a plain `DateTimeImmutable` with nothing nullable to
        // check. Not this app's actual earliest possible row (there is no
        // real ceiling on how old a booking could be) -- a chart spanning
        // decades to show a few months of real data is a flat line with a
        // bump at the end, which answers nothing a shorter one does not.
        $from = $days === null ? $to->modify('-1 year') : $to->modify("-{$days} days");
        $prevFrom = $days === null ? null : $from->modify("-{$days} days");
        $prevTo = $from;

        $made = $bookings->madeCount($from, $to);
        $cancelled = $bookings->cancelledCount($from, $to);
        $cost = $bookings->totalCost($from, $to);
        $searchTotal = $searches->total($from, $to);
        $ratio = $searchTotal > 0 ? ($made / $searchTotal) * 100 : 0.0;

        $prevMade = $prevFrom !== null ? $bookings->madeCount($prevFrom, $prevTo) : null;
        $prevCancelled = $prevFrom !== null ? $bookings->cancelledCount($prevFrom, $prevTo) : null;
        $prevCost = $prevFrom !== null ? $bookings->totalCost($prevFrom, $prevTo) : null;

        if ($prevFrom !== null) {
            $prevSearchTotal = $searches->total($prevFrom, $prevTo);
            $prevRatio = $prevSearchTotal > 0 ? ($prevMade / $prevSearchTotal) * 100 : null;
        } else {
            $prevRatio = null;
        }

        $madeByDay = $bookings->dailyMadeCounts($from, $to);
        $searchesByDay = $searches->dailyCounts($from, $to);

        $ratioByDay = [];

        foreach ($madeByDay as $date => $madeThatDay) {
            $searchedThatDay = $searchesByDay[$date] ?? 0;
            $ratioByDay[$date] = $searchedThatDay > 0 ? ($madeThatDay / $searchedThatDay) * 100 : 0;
        }

        return [
            [
                'key' => 'made',
                'label' => 'Made',
                'display' => number_format($made),
                'tone' => 'good',
                'delta' => self::delta($made, $prevMade),
                'points' => $madeByDay,
            ],
            [
                'key' => 'cancelled',
                'label' => 'Cancelled',
                'display' => number_format($cancelled),
                'tone' => 'bad',
                'delta' => self::delta($cancelled, $prevCancelled, upIsGood: false),
                'points' => $bookings->dailyCancelledCounts($from, $to),
            ],
            [
                'key' => 'cost',
                'label' => 'Total cost',
                'display' => new ItineraryPresenter()->priceParts($cost)['text'],
                'tone' => 'neutral',
                'delta' => self::delta($cost, $prevCost),
                'points' => $bookings->dailyCostSums($from, $to),
            ],
            [
                'key' => 'ratio',
                'label' => 'Search-to-book',
                'display' => number_format($ratio, 1) . '%',
                'tone' => 'good',
                'delta' => self::delta($ratio, $prevRatio),
                'points' => $ratioByDay,
            ],
        ];
    }

    /**
     * How far `$current` moved from `$previous`, or null when there is no
     * previous period to compare against (`all`, or a period that started
     * at zero -- a move off zero has no percentage, only an origin).
     *
     * @return array{pct: float, good: bool}|null
     */
    private static function delta(int|float $current, int|float|null $previous, bool $upIsGood = true): ?array
    {
        if ($previous === null || $previous == 0) {
            return null;
        }

        $pct = (($current - $previous) / $previous) * 100;

        return ['pct' => $pct, 'good' => $upIsGood ? $pct >= 0 : $pct <= 0];
    }

    /**
     * @param BookingRow $row
     */
    private static function bookingId(array $row): int
    {
        return $row['id'];
    }

    /**
     * Every booking the current search and filters match, as a download.
     *
     * `exportFiltered()` and not `filtered()`: every filter carries over, the
     * page does not -- a CSV capped at 25 rows isn't an export (G3.5, #308).
     * Columns mirror the list table exactly, not the detail page: no
     * address, no phone, no date of birth, same restraint `bookings.html.twig`
     * already documents for the list itself.
     */
    public function exportBookings(): void
    {
        if (!$this->guard()) {
            return;
        }

        $bookings = new BookingRepository($this->connection());
        $term = mb_substr(trim($this->request->query->str('q', '')), 0, 64);
        $status = BookingStatus::tryFrom($this->request->query->str('status', ''));
        $from = self::validDate($this->request->query->str('from', ''));
        $to = self::validDate($this->request->query->str('to', ''));
        $missingTicket = $this->request->query->str('missing_ticket', '') === '1';

        $rows = $bookings->exportFiltered($term, $status, $from, $to, $missingTicket);

        $names = $rows === []
            ? []
            : new BookingPassengerRepository($this->connection())->namesFor(array_map(self::bookingId(...), $rows));

        $lines = [];

        foreach ($rows as $row) {
            $listed = $this->listed($row, $names[$row['id']] ?? []);
            $travellers = $listed['others'] === []
                ? $listed['lead']
                : $listed['lead'] . '; ' . implode(', ', $listed['others']);

            $lines[] = [
                $listed['id'],
                $listed['reference'],
                $travellers,
                $listed['from'],
                $listed['to'],
                $listed['departs']?->format('Y-m-d'),
                $listed['created'],
                $listed['price_total']['text'] ?? null,
                $listed['status_label'],
            ];
        }

        $this->downloadCsv(
            'bookings-' . date('Y-m-d') . '.csv',
            ['ID', 'Reference', 'Travellers', 'From', 'To', 'Departs', 'Made', 'Total', 'Status'],
            $lines,
        );
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

        echo new TwigRenderer()->render('admin/booking.html.twig', $this->bookingViewData($id, $row));
    }

    /**
     * Everything the booking page, and a `fetch()` answer to a POST on it,
     * both need to draw -- one place rather than two, so the two can never
     * quietly drift apart on what a booking's page actually shows.
     *
     * `$row` is the caller's to fetch: the GET path already has one, and the
     * POST path needs a fresh one after whatever it just changed, so neither
     * a stale copy nor a second query is forced on the other (G8.3, #338).
     *
     * @param BookingRow $row
     *
     * @return array<string, mixed>
     */
    private function bookingViewData(int $id, array $row): array
    {
        $travellers = new BookingPassengerRepository($this->connection());
        $passengers = $travellers->forBooking($id);
        $booking = new BookingPresenter()->booking($row, $passengers);
        $events = new BookingEventRepository($this->connection());
        $counts = $travellers->bookingCountsFor($passengers);
        $log = $events->forBooking($id);

        return [
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
                // The raw stored code, not the presenter's `price_base.code` --
                // that one falls back to CAD on a corrupt row, which is the
                // right call for showing a price but the wrong one for saying
                // what currency the buyer was actually charged in (G8.1, #336).
                'currency' => $row['currency'],
                'language' => $row['language'],
                'ip_address' => $row['ip_address'],
                'city' => $row['city'],
                'country' => $row['country'],
                'country_name' => $row['country'] === null
                    ? null
                    : (new CountryRepository($this->connection())->all()[$row['country']] ?? null),
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
            'log' => $log,
            // When the log itself began, so a booking with no events can say
            // why rather than reading as one nothing ever happened to.
            'log_from' => $events->startedAt(),
            'remarks' => new BookingRemarkRepository($this->connection())->forBooking($id),
            'remark_tones' => RemarkTone::cases(),
            'tickets' => new BookingTicketRepository($this->connection())->forBooking($id),
            'document_types' => DocumentType::cases(),
            'ticket_statuses' => TicketStatus::cases(),
            // Who created the booking, for the payment breakdown -- off the
            // log's own `Booked` line rather than a new column, and unknown
            // rather than assumed for a booking older than the log itself
            // (same honesty the log's own empty state already uses).
            'payment_created_by' => self::bookedBy($log),
        ];
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
        $asJson = $this->wantsJson();

        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->respondBooking($asJson, $id, $back, 'That form went stale. Try again.', FlashTone::Error);

            return;
        }

        $action = $this->request->body->str('action');

        if ($action === 'remark') {
            $this->addRemark($id, $back, $asJson);

            return;
        }

        if ($action === 'ticket_add') {
            $this->addTicket($id, $back, $asJson);

            return;
        }

        if ($action === 'ticket_status') {
            $this->setTicketStatus($id, $back, $asJson);

            return;
        }

        if ($action === 'ticket_remove') {
            $this->removeTicket($id, $back, $asJson);

            return;
        }

        if ($action === 'ticket_number') {
            $this->updateTicketNumber($id, $back, $asJson);

            return;
        }

        if ($action === 'ticket_generate') {
            $this->generateTickets($id, $back, $asJson);

            return;
        }

        $change = match ($action) {
            'cancel' => [
                'to' => BookingStatus::Cancelled,
                'from' => BookingStatus::Confirmed,
                'event' => BookingEvent::Cancelled,
                'label' => 'Booking cancelled.',
            ],
            'reinstate' => [
                'to' => BookingStatus::Confirmed,
                'from' => BookingStatus::Cancelled,
                'event' => BookingEvent::Reinstated,
                'label' => 'Booking reinstated.',
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

            $this->respondBooking($asJson, $id, $back, $change['label'], FlashTone::Success);
        } else {
            // Either the button posted something this action does not know,
            // or the row had already moved -- somebody cancelled it in
            // another tab since this page was opened.
            $this->respondBooking(
                $asJson,
                $id,
                $back,
                'Nothing changed. This booking may already be in that state.',
                FlashTone::Error,
            );
        }
    }

    /**
     * Whether this POST wants its answer back in the hand rather than a
     * redirect -- the same `Accept` header `AjaxController::subscribe()`
     * already reads, so a script and a plain form posting the same action
     * are told apart the same way there. `admin.js`'s `ajaxBookingForms()`
     * sends it on every form this page owns.
     */
    private function wantsJson(): bool
    {
        return str_contains((string) $this->request->header('Accept'), 'application/json');
    }

    /**
     * Answer a booking-page POST: a redirect carrying a flash message for a
     * plain form post, or JSON carrying that message plus a fresh render of
     * the page for a script to drop in.
     *
     * The whole content block, not just whatever the action touched -- a
     * ticket action can also move the "Tickets" count in the detail strip and
     * add a line to the log below, and one re-render is the one way that can
     * never miss one of those the way patching each card by hand eventually
     * would (G8.3, #338).
     */
    private function respondBooking(bool $asJson, int $id, string $back, string $message, FlashTone $tone): void
    {
        if (!$asJson) {
            Flash::set($message, $tone);
            $this->bounce($back);

            return;
        }

        $row = new BookingRepository($this->connection())->find($id);

        // Nothing in this app deletes a booking, so this is here to keep the
        // method honest rather than because it is expected to run.
        if ($row === null) {
            http_response_code(HttpStatus::InternalServerError->value);

            return;
        }

        $data = $this->bookingViewData($id, $row);
        $twig = new TwigRenderer();

        header('Content-type: application/json; charset=utf-8');
        http_response_code(($tone === FlashTone::Success ? HttpStatus::Ok : HttpStatus::UnprocessableEntity)->value);
        echo json_encode([
            'status' => $tone === FlashTone::Success ? 'ok' : 'error',
            'message' => $message,
            'tone' => $tone->bootstrapClass(),
            'content_html' => $twig->render('admin/partials/booking-content.html.twig', $data),
            'tools_html' => $twig->render('admin/partials/booking-tools.html.twig', ['extra' => $data['extra']]),
        ]);
    }

    /**
     * Leave a remark on a booking.
     *
     * A separate method rather than another `match` arm in `actOnBooking()`:
     * a remark is not a status transition, so it has nothing to check itself
     * against and nothing to undo. Flashed on success or failure rather than
     * shown inline -- this page has no other field-level validation either,
     * and `BookingRemarkRepository::record()` does not swallow its own
     * errors, so a write that fails is a write this method has to know about.
     */
    private function addRemark(int $id, string $back, bool $asJson): void
    {
        $body = trim($this->request->body->str('body'));
        $tone = RemarkTone::tryFrom($this->request->body->str('tone'));

        if ($body === '' || $tone === null) {
            $this->respondBooking($asJson, $id, $back, 'A remark needs a mark and some text.', FlashTone::Error);

            return;
        }

        if (mb_strlen($body) > self::REMARK_MAX_LENGTH) {
            $this->respondBooking(
                $asJson,
                $id,
                $back,
                sprintf('%d characters at most.', self::REMARK_MAX_LENGTH),
                FlashTone::Error,
            );

            return;
        }

        new BookingRemarkRepository($this->connection())->record($id, BookingActor::Operator, $tone, $body);
        $this->respondBooking($asJson, $id, $back, 'Remark added.', FlashTone::Success);
    }

    /**
     * Add a travel document by hand.
     *
     * The passenger dropdown only ever offers this booking's own travellers,
     * but a tampered POST could name somebody else's -- checked against a
     * fresh read of `booking_passengers` rather than trusted from the form,
     * the same caution `BookingTicketRepository::setStatus()`/`remove()`
     * apply to an existing row.
     */
    private function addTicket(int $id, string $back, bool $asJson): void
    {
        $passengerId = (int) $this->request->body->str('booking_passenger_id');
        $type = DocumentType::tryFrom($this->request->body->str('document_type'));
        $number = trim($this->request->body->str('document_number'));
        $issueDate = date_create_immutable($this->request->body->str('issue_date') ?: 'invalid');

        $passengers = new BookingPassengerRepository($this->connection())->forBooking($id);
        $passenger = null;

        foreach ($passengers as $candidate) {
            if ($candidate['id'] === $passengerId) {
                $passenger = $candidate;

                break;
            }
        }

        if ($passenger === null || $type === null || $number === '' || $issueDate === false) {
            $this->respondBooking(
                $asJson,
                $id,
                $back,
                'A ticket needs a passenger, a document type, a number and a real issue date.',
                FlashTone::Error,
            );

            return;
        }

        if (mb_strlen($number) > self::DOCUMENT_NUMBER_MAX_LENGTH) {
            $this->respondBooking(
                $asJson,
                $id,
                $back,
                sprintf('%d characters at most.', self::DOCUMENT_NUMBER_MAX_LENGTH),
                FlashTone::Error,
            );

            return;
        }

        new BookingTicketRepository($this->connection())->create(
            $passengerId,
            $type,
            $number,
            TicketStatus::Issued,
            $issueDate->format('Y-m-d'),
        );
        new BookingEventRepository($this->connection())->record(
            $id,
            BookingEvent::TicketAdded,
            BookingActor::Operator,
            sprintf('%s for %s', $type->label(), trim($passenger['first_name'] . ' ' . $passenger['last_name'])),
        );
        $this->respondBooking($asJson, $id, $back, 'Ticket added.', FlashTone::Success);
    }

    /** Change one document's status, scoped to this booking. */
    private function setTicketStatus(int $id, string $back, bool $asJson): void
    {
        $ticketId = (int) $this->request->body->str('ticket_id');
        $status = TicketStatus::tryFrom($this->request->body->str('status'));
        $tickets = new BookingTicketRepository($this->connection());
        $ticket = $tickets->find($ticketId, $id);

        if ($status === null || $ticket === null || $tickets->setStatus($ticketId, $id, $status) === 0) {
            $this->respondBooking(
                $asJson,
                $id,
                $back,
                'Nothing changed. This ticket may not exist any more.',
                FlashTone::Error,
            );

            return;
        }

        new BookingEventRepository($this->connection())->record(
            $id,
            BookingEvent::TicketStatusChanged,
            BookingActor::Operator,
            sprintf('%s for %s marked %s', self::ticketKind($ticket), $ticket['passenger'], strtolower($status->label())),
        );
        $this->respondBooking($asJson, $id, $back, sprintf('Ticket marked %s.', strtolower($status->label())), FlashTone::Success);
    }

    /** Remove one document, scoped to this booking. */
    private function removeTicket(int $id, string $back, bool $asJson): void
    {
        $ticketId = (int) $this->request->body->str('ticket_id');
        $tickets = new BookingTicketRepository($this->connection());
        $ticket = $tickets->find($ticketId, $id);

        if ($ticket === null || $tickets->remove($ticketId, $id) === 0) {
            $this->respondBooking(
                $asJson,
                $id,
                $back,
                'Nothing changed. This ticket may not exist any more.',
                FlashTone::Error,
            );

            return;
        }

        new BookingEventRepository($this->connection())->record(
            $id,
            BookingEvent::TicketRemoved,
            BookingActor::Operator,
            sprintf('%s for %s (%s)', self::ticketKind($ticket), $ticket['passenger'], $ticket['document_number']),
        );
        $this->respondBooking($asJson, $id, $back, 'Ticket removed.', FlashTone::Success);
    }

    /** Correct a mistyped document number, scoped to this booking. */
    private function updateTicketNumber(int $id, string $back, bool $asJson): void
    {
        $ticketId = (int) $this->request->body->str('ticket_id');
        $number = trim($this->request->body->str('document_number'));
        $tickets = new BookingTicketRepository($this->connection());
        $ticket = $tickets->find($ticketId, $id);

        if ($ticket === null || $number === '') {
            $this->respondBooking(
                $asJson,
                $id,
                $back,
                'Nothing changed. This ticket may not exist any more.',
                FlashTone::Error,
            );

            return;
        }

        if (mb_strlen($number) > self::DOCUMENT_NUMBER_MAX_LENGTH) {
            $this->respondBooking(
                $asJson,
                $id,
                $back,
                sprintf('%d characters at most.', self::DOCUMENT_NUMBER_MAX_LENGTH),
                FlashTone::Error,
            );

            return;
        }

        // A re-save of the same value is not a change worth a log line --
        // the button offers no other way to reach this branch, but a
        // double-submit does.
        if ($number === $ticket['document_number']) {
            $this->respondBooking($asJson, $id, $back, 'Ticket number unchanged.', FlashTone::Success);

            return;
        }

        $tickets->updateNumber($ticketId, $id, $number);
        new BookingEventRepository($this->connection())->record(
            $id,
            BookingEvent::TicketNumberChanged,
            BookingActor::Operator,
            sprintf('%s for %s: %s to %s', self::ticketKind($ticket), $ticket['passenger'], $ticket['document_number'], $number),
        );
        $this->respondBooking($asJson, $id, $back, 'Ticket number updated.', FlashTone::Success);
    }

    /**
     * Add one fake ticket for every passenger who holds no document yet.
     *
     * Always a `Ticket`, never a random pick between that and `EMD` --
     * every passenger needs at least a ticket to fly, and inventing a mix
     * nobody asked for would just be noise on top of a fake number.
     */
    private function generateTickets(int $id, string $back, bool $asJson): void
    {
        $tickets = new BookingTicketRepository($this->connection());
        $missing = $tickets->passengersWithoutTickets($id);

        foreach ($missing as $passengerId) {
            $tickets->create($passengerId, DocumentType::Ticket, self::fakeTicketNumber(), TicketStatus::Issued, date('Y-m-d'));
        }

        if ($missing !== []) {
            new BookingEventRepository($this->connection())->record(
                $id,
                BookingEvent::TicketAdded,
                BookingActor::Operator,
                sprintf('%d dummy ticket(s) generated', count($missing)),
            );
        }

        $this->respondBooking(
            $asJson,
            $id,
            $back,
            $missing === [] ? 'Every passenger already has a ticket.' : sprintf('%d dummy ticket(s) added.', count($missing)),
            FlashTone::Success,
        );
    }

    /**
     * A document's own kind, the way the log and the flash both say it --
     * the case where this version knows the word, or the raw one where it
     * does not.
     *
     * @param array{document_type: ?DocumentType, raw_document_type: string} $ticket
     */
    private static function ticketKind(array $ticket): string
    {
        return $ticket['document_type'] ? $ticket['document_type']->label() : $ticket['raw_document_type'];
    }

    /** A number that reads like a real e-ticket number and is not one. */
    private static function fakeTicketNumber(): string
    {
        return sprintf('%03d%010d', random_int(1, 999), random_int(0, 9999999999));
    }

    /**
     * Who made the booking, read off its own `BookingEvent::Booked` line --
     * null when the row predates the log, same as every other fact this
     * page only knows from it (G8.5, #340).
     *
     * @param list<array{event: ?BookingEvent, raw: string, actor: ?BookingActor, note: string, at: string}> $log
     */
    private static function bookedBy(array $log): ?BookingActor
    {
        foreach ($log as $line) {
            if ($line['event'] === BookingEvent::Booked) {
                return $line['actor'];
            }
        }

        return null;
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

    /** Every address on the list, as a download. There is nothing to filter here. */
    public function exportSubscribers(): void
    {
        if (!$this->guard()) {
            return;
        }

        $subscribers = new SubscriberRepository($this->connection());
        $lines = array_map(
            static fn(array $subscriber): array => [$subscriber['email'], $subscriber['subscribed_at']],
            $subscribers->exportAll(),
        );

        $this->downloadCsv('subscribers-' . date('Y-m-d') . '.csv', ['Address', 'Subscribed'], $lines);
    }

    /**
     * The list's action: take one address off it, or several at once.
     *
     * `ids` (an array) means the bulk toolbar's own form; `id` (a single
     * value) means a row's own "Remove" -- the two never collide, since the
     * bulk form and a row's form are separate `<form>` elements (G3.7,
     * #310). Whichever way E23 (#188) eventually gives a visitor their own
     * way off this list, it belongs here too.
     */
    private function removeSubscriber(): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Flash::set('That form went stale. Try again.', FlashTone::Error);
            $this->bounce('/admin/subscribers');

            return;
        }

        $subscribers = new SubscriberRepository($this->connection());
        $events = new AdminEventRepository($this->connection());
        $raw = $this->request->body->raw('ids');

        if (is_array($raw)) {
            $ids = array_values(array_unique(array_map(intval(...), $raw)));
            // Read before removing: the address is the one thing worth
            // logging, and a deleted row cannot answer for itself afterwards.
            $emails = $subscribers->emailsFor($ids);
            $removed = $subscribers->removeMany($ids);

            foreach ($emails as $id => $email) {
                $events->record(AdminEventResource::Subscriber, (string) $id, AdminEvent::Removed, $email);
            }

            Flash::set($removed === 1 ? 'Removed from the fare-alert list.' : $removed . ' removed from the fare-alert list.');
        } else {
            $id = $this->request->body->int('id');
            $email = $subscribers->emailsFor([$id])[$id] ?? null;

            if ($subscribers->remove($id) && $email !== null) {
                $events->record(AdminEventResource::Subscriber, (string) $id, AdminEvent::Removed, $email);
            }

            Flash::set('Removed from the fare-alert list.');
        }

        $this->bounce('/admin/subscribers');
    }

    /**
     * Fare alerts' other list -- who is watching a route, and how the
     * routes and the sending are doing (C6, #155; G21, #385).
     *
     * The same range buckets `searches()` already offers, since both are
     * "how has this been going" trends over a window rather than a page of
     * its own filters.
     */
    public function subscribersRoutes(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->removeRouteWatch();

            return;
        }

        $range = $this->request->query->str('range', '7d');
        $range = array_key_exists($range, self::SEARCH_RANGE_DAYS) ? $range : '7d';

        $watches = new RouteWatchRepository($this->connection());
        $term = $this->request->query->nullableStr('q');
        $page = max(1, (int) $this->request->query->str('page', '1'));
        $offset = ($page - 1) * self::PER_PAGE;

        echo new TwigRenderer()->render('admin/subscribers-routes.html.twig', [
            'range' => $range,
            'range_label' => match ($range) {
                '1d' => 'today',
                '7d' => 'in the last 7 days',
                '30d' => 'in the last 30 days',
                '90d' => 'in the last 90 days',
                '180d' => 'in the last 6 months',
                '365d' => 'in the last year',
            },
            'term' => $term,
            'hero' => $this->routeWatchHero($watches, $range),
            'active_count' => number_format($watches->count()),
            'triggered_count' => number_format($watches->triggeredCount()),
            'top_routes' => array_map(
                static fn(array $row): array => $row + ['cabin_label' => CabinClass::from($row['cabin'])->label()],
                $watches->topRoutes(self::TOP_WATCHED_ROUTES_LIMIT),
            ),
            'route_count' => $watches->distinctRouteCount(),
            'watches' => array_map(
                static fn(array $row): array => $row + [
                    'cabin_label' => CabinClass::from($row['cabin'])->label(),
                    // Always CAD, whatever currency the operator's own
                    // cookie happens to say -- the same money
                    // `alerts:check` actually sends against, not the
                    // visitor-facing conversion `Money::active()` gives.
                    'threshold_price' => new ItineraryPresenter()->priceParts($row['threshold'], Money::base()),
                    'notified_price_display' => $row['notified_price'] === null
                        ? null
                        : new ItineraryPresenter()->priceParts($row['notified_price'], Money::base()),
                ],
                $watches->paginated(self::PER_PAGE, $offset, $term),
            ),
            'total' => $watches->countMatching($term),
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ]);
    }

    /**
     * The two period-scoped cards -- new watches and alerts sent, each with
     * a delta against the equal-length period before it and a day-by-day
     * sparkline -- the same shape {@see hero()} already gives Bookings, cut
     * down to the two series this page actually has (G21, #385).
     *
     * @return list<array{key: string, label: string, display: string, tone: string, delta: array{pct: float, good: bool}|null, points: array<string, int>}>
     */
    private function routeWatchHero(RouteWatchRepository $watches, string $range): array
    {
        $to = new DateTimeImmutable('tomorrow');
        $days = self::SEARCH_RANGE_DAYS[$range];
        $from = $to->modify("-{$days} days");
        $prevFrom = $from->modify("-{$days} days");
        $prevTo = $from;

        $createdByDay = $watches->dailyCreatedCounts($from, $to);
        $sentByDay = $watches->dailySentCounts($from, $to);

        $created = array_sum($createdByDay);
        $prevCreated = array_sum($watches->dailyCreatedCounts($prevFrom, $prevTo));
        $sent = $watches->totalSent($from, $to);
        $prevSent = $watches->totalSent($prevFrom, $prevTo);

        return [
            [
                'key' => 'created',
                'label' => 'New watches',
                'display' => number_format($created),
                'tone' => 'neutral',
                'delta' => self::delta($created, $prevCreated),
                'points' => $createdByDay,
            ],
            [
                'key' => 'sent',
                'label' => 'Alerts sent',
                'display' => number_format($sent),
                'tone' => 'good',
                'delta' => self::delta($sent, $prevSent),
                'points' => $sentByDay,
            ],
        ];
    }

    /**
     * The route-watches list's own removal, the same shape
     * {@see removeSubscriber()} already gives the general list.
     */
    private function removeRouteWatch(): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Flash::set('That form went stale. Try again.', FlashTone::Error);
            $this->bounce('/admin/subscribers/routes');

            return;
        }

        $watches = new RouteWatchRepository($this->connection());
        $events = new AdminEventRepository($this->connection());
        $raw = $this->request->body->raw('ids');

        if (is_array($raw)) {
            $ids = array_values(array_unique(array_map(intval(...), $raw)));
            $descriptors = $watches->descriptorsFor($ids);
            $removed = $watches->removeMany($ids);

            foreach ($descriptors as $id => $descriptor) {
                $events->record(AdminEventResource::RouteWatch, (string) $id, AdminEvent::Removed, $descriptor);
            }

            Flash::set($removed === 1 ? 'Removed the watch.' : $removed . ' watches removed.');
        } else {
            $id = $this->request->body->int('id');
            $descriptor = $watches->descriptorsFor([$id])[$id] ?? null;

            if ($watches->remove($id) && $descriptor !== null) {
                $events->record(AdminEventResource::RouteWatch, (string) $id, AdminEvent::Removed, $descriptor);
            }

            Flash::set('Removed the watch.');
        }

        $this->bounce('/admin/subscribers/routes');
    }

    /**
     * `PanelSetting::group()`'s three labels, and the URL/slug each answers
     * to -- a routing concern, kept off the enum itself the same way
     * `overview.html.twig`'s icon map keeps a domain enum from knowing what
     * glyph it wears (G2.2, #288; G2.6, #299).
     *
     * @var array<string, array{slug: string, path: string}>
     */
    private const array SETTINGS_GROUPS = [
        'Search rules' => ['slug' => 'search-rules', 'path' => '/admin/settings'],
        'Site identity' => ['slug' => 'site-identity', 'path' => '/admin/settings/site-identity'],
        'Map' => ['slug' => 'map', 'path' => '/admin/settings/map'],
    ];

    // Not in `SETTINGS_GROUPS` above: the profile page is reached from the
    // topbar user menu, not a Settings rail child, and draws its own
    // template (G9, #350) rather than `admin/settings.html.twig`.
    private const string PROFILE_PATH = '/admin/profile';

    /**
     * The settings a config file no longer has the last word on.
     *
     * @throws Exception|Error
     */
    public function settings(): void
    {
        $this->settingsGroup('Search rules');
    }

    /** @throws Exception|Error */
    public function settingsSiteIdentity(): void
    {
        $this->settingsGroup('Site identity');
    }

    /** @throws Exception|Error */
    public function settingsMap(): void
    {
        $this->settingsGroup('Map');
    }

    /**
     * A settings child of its own rather than a fourth `PanelSetting` group:
     * a diagnostic is something run, not a field that is saved, and the two
     * do not share a template honestly.
     *
     * One check exists today (Mailtrap). Adding another is a case in
     * `runDiagnostic()`'s `match` and a form of its own in the template --
     * deliberately not a registry or an interface for the one check this
     * page has.
     *
     * @throws Exception|Error
     */
    public function settingsDiagnostics(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->runDiagnostic();

            return;
        }

        echo new TwigRenderer()->render('admin/settings-diagnostics.html.twig', [
            'mailtrap_sandboxed' => Env::get(EnvKey::MailtrapSandboxInboxId) !== '',
        ]);
    }

    /**
     * A redirect carrying a flash for a plain form post, or JSON for the
     * page's own script -- `wantsJson()` is the same `Accept` check
     * `respondBooking()` already answers to. No HTML to re-render here,
     * unlike that one: nothing else on this page changes when a check runs.
     */
    private function runDiagnostic(): void
    {
        $asJson = $this->wantsJson();

        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->respondDiagnostic($asJson, 'That form went stale. Try again.', FlashTone::Error);

            return;
        }

        [$ok, $message] = match ($this->request->body->str('check')) {
            'mailtrap' => $this->testMailtrap(),
            default => [false, 'Not a real check.'],
        };

        $this->respondDiagnostic($asJson, $message, $ok ? FlashTone::Success : FlashTone::Error);
    }

    private function respondDiagnostic(bool $asJson, string $message, FlashTone $tone): void
    {
        if (!$asJson) {
            Flash::set($message, $tone);
            $this->bounce('/admin/settings/diagnostics');

            return;
        }

        header('Content-type: application/json; charset=utf-8');
        http_response_code(($tone === FlashTone::Success ? HttpStatus::Ok : HttpStatus::UnprocessableEntity)->value);
        echo json_encode([
            'status' => $tone === FlashTone::Success ? 'ok' : 'error',
            'message' => $message,
            'tone' => $tone->bootstrapClass(),
        ]);
    }

    /**
     * Send one real email through Mailtrap and say exactly what happened.
     *
     * The failure message is shown, not logged and hidden -- unlike a public
     * endpoint's own errors (`AjaxController::subscribe()` and its
     * siblings), which stay in the log because a visitor cannot act on one.
     * This page exists for the operator to read that message and fix
     * whatever it names: a missing `MAILTRAP_API_TOKEN`, an unverified
     * domain, Mailtrap's own refusal.
     *
     * Sandbox first, real send only as the fallback when none is configured
     * -- `Mailtrap::sandbox()`'s own contract, not a choice made here.
     * `MAILTRAP_SANDBOX_INBOX_ID` is meant to stay unset on a production
     * server, so this page sending for real there is the intended
     * behaviour, not a gap.
     *
     * @return array{0: bool, 1: string}
     */
    private function testMailtrap(): array
    {
        $to = trim($this->request->body->str('to'));

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Give a real email address to send the test to.'];
        }

        try {
            $mailer = Mailtrap::sandbox() ?? Mailtrap::fromEnvironment();

            $mailer->send(
                $to,
                'Trip Builder: Mailtrap test',
                "This is a test email from the admin panel's Diagnostics page. "
                . 'If you are reading this, Mailtrap is configured correctly.',
            );
        } catch (Throwable $e) {
            return [false, 'Mailtrap test failed: ' . $e->getMessage()];
        }

        return $mailer->sandboxInboxId === null
            ? [true, 'Sent for real. Check ' . $to . "'s inbox, or Mailtrap's own logs."]
            : [true, 'Sent to sandbox #' . $mailer->sandboxInboxId . ' -- ' . $to . ' received nothing.'];
    }

    /**
     * One rail child, one page, one save -- three sub-pages instead of the
     * single form all twelve fields once shared (G2.6, #299). Everything
     * below only ever sees the fields belonging to `$group`.
     *
     * @throws Exception|Error
     */
    private function settingsGroup(string $group): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->postSettings($group);

            return;
        }

        $this->settingsForm($group);
    }

    /**
     * One POST, two things it might mean: reset a single field (its own
     * button posts only its own name), or save whatever the form is holding.
     */
    private function postSettings(string $group): void
    {
        $path = self::SETTINGS_GROUPS[$group]['path'];

        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Flash::set('That form went stale. Try again.', FlashTone::Error);
            $this->bounce($path);

            return;
        }

        $resetKey = $this->request->body->nullableStr('reset_key');

        if ($resetKey !== null) {
            if (PanelSetting::tryFrom($resetKey) !== null) {
                new SettingsRepository($this->connection())->remove($resetKey);
                Settings::forget();
                Flash::set('Reset to the config default.');
            }

            $this->bounce($path);

            return;
        }

        $errors = $this->saveSettings($group);

        if ($errors !== []) {
            $this->settingsForm($group, $errors);

            return;
        }

        Settings::forget();
        Flash::set(ucfirst($group) . ' settings saved.');
        $this->bounce($path);
    }

    /**
     * Every field in `$group`, validated together and written only if every
     * one of them is fine -- a form half saved is a form that lied about
     * which values are actually in effect. Independent of the other two
     * groups since G2.6 (#299): a typo in Search rules no longer blocks a
     * Site identity save.
     *
     * @return array<string, string> the key of each invalid field, and why
     */
    private function saveSettings(string $group): array
    {
        $posted = $this->request->body->raw('settings');
        $posted = is_array($posted) ? $posted : [];

        $errors = [];
        $parsed = [];

        foreach (PanelSetting::cases() as $setting) {
            if ($setting->group() !== $group) {
                continue;
            }

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
            // The form posts every field in the group back, touched or not,
            // so `$parsed` is not "the fields that changed" -- it is all of
            // them. `SettingsRepository::set()` only refuses to repeat an
            // existing override, comparing against the raw override table;
            // a field with no override yet, posted back holding the same
            // value its config default already gives it, has no prior
            // override to match against, so that guard never fires and it
            // writes and logs a "change" to a value the field already
            // effectively had. Comparing here against what this field
            // actually reads as right now -- override or config default
            // alike -- is what "changed" has to mean from the operator's
            // side (found after G3.1, #304, made every save visible enough
            // for this to be noticed at all).
            if (json_encode($value, JSON_THROW_ON_ERROR) === json_encode(Settings::get($key), JSON_THROW_ON_ERROR)) {
                continue;
            }

            $repository->set($key, $value);
        }

        return [];
    }

    /**
     * @param array<string, string> $errors keyed by the field that failed
     * @throws Exception|Error
     */
    private function settingsForm(string $group, array $errors = []): void
    {
        $fields = $this->settingsFields($group, $errors);

        // Shared across all three pages rather than filtered per group: a
        // change to Map is still worth seeing while looking at Search
        // rules, and filtering would need a fourth column nobody asked for.
        $history = array_map(
            self::historyLine(...),
            new SettingsRepository($this->connection())->history(20),
        );

        echo new TwigRenderer()->render('admin/settings.html.twig', [
            'group' => $group,
            'group_slug' => self::SETTINGS_GROUPS[$group]['slug'],
            'path' => self::SETTINGS_GROUPS[$group]['path'],
            'fields' => $fields,
            'history' => $history,
        ]);
    }

    /**
     * Every `PanelSetting` in `$group`, shaped for a form -- shared by the
     * settings pages and the profile page (G9, #350), which draws the same
     * "overridden, reset to default" affordance in a different template.
     *
     * @param array<string, string> $errors keyed by the field that failed
     * @return list<array{key: string, label: string, reason: string, is_list: bool, overridden: bool, error: ?string, value: string}>
     */
    private function settingsFields(string $group, array $errors): array
    {
        /** @var array<string, mixed> $overrides */
        $overrides = new SettingsRepository($this->connection())->all();
        $posted = $errors === [] ? null : $this->request->body->raw('settings');
        $posted = is_array($posted) ? $posted : [];

        $fields = [];

        foreach (PanelSetting::cases() as $setting) {
            if ($setting->group() !== $group) {
                continue;
            }

            $key = $setting->value;
            $raw = $posted[$key] ?? null;

            $fields[] = [
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

        return $fields;
    }

    /**
     * The operator's own identity page (G9, #350) -- name, email, role and
     * avatar, editable through the same `PanelSetting` mechanism the other
     * settings pages use, plus a read-only Password tab: there is no in-app
     * rotation to offer, since `ADMIN_PASSWORD_HASH` is a `.env` value
     * nothing here can write back to (A3.2, #100).
     *
     * @throws Exception|Error
     */
    public function profile(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->postProfile();

            return;
        }

        $this->profileForm();
    }

    private function postProfile(): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Flash::set('That form went stale. Try again.', FlashTone::Error);
            $this->bounce(self::PROFILE_PATH);

            return;
        }

        $resetKey = $this->request->body->nullableStr('reset_key');

        if ($resetKey !== null) {
            if (PanelSetting::tryFrom($resetKey) !== null) {
                new SettingsRepository($this->connection())->remove($resetKey);
                Settings::forget();
                Flash::set('Reset to the config default.');
            }

            $this->bounce(self::PROFILE_PATH);

            return;
        }

        $errors = $this->saveSettings('Profile');

        if ($errors !== []) {
            $this->profileForm($errors);

            return;
        }

        Settings::forget();
        Flash::set('Profile saved.');
        $this->bounce(self::PROFILE_PATH);
    }

    /**
     * @param array<string, string> $errors keyed by the field that failed
     * @throws Exception|Error
     */
    private function profileForm(array $errors = []): void
    {
        echo new TwigRenderer()->render('admin/profile.html.twig', [
            'fields' => $this->settingsFields('Profile', $errors),
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
     * One `admin_events` row, read as a sentence for the Overview page.
     *
     * `resource`/`event` fall back to their raw stored word when this
     * version does not recognise them, the same reasoning
     * {@see historyValue()} gives for a dropped `PanelSetting`.
     *
     * @param array{resource: ?AdminEventResource, resource_raw: string, resource_id: string, event: ?AdminEvent, event_raw: string, note: string, at: string} $event
     * @return array{label: string, ago: string}
     */
    private static function activityLine(array $event): array
    {
        $resource = $event['resource']?->label() ?? ucfirst($event['resource_raw']);
        $action = $event['event']?->label() ?? $event['event_raw'];
        $note = $event['note'] !== '' ? ': ' . $event['note'] : '';

        return [
            'label' => $resource . ' ' . $action . $note,
            'ago' => Helper::elapsed($event['at']),
        ];
    }

    /**
     * The full change log, as a download -- one export for all three groups,
     * the same as the page's own "Recent changes" table reads across all of
     * them rather than filtering by which group is open. `history(null)`
     * and not `history(20)`: an audit trail capped at twenty rows isn't one
     * (G3.5, #308).
     */
    public function exportSettingsHistory(): void
    {
        if (!$this->guard()) {
            return;
        }

        $lines = array_map(
            static fn(array $line): array => [$line['key'], $line['from'], $line['to'], $line['changed_at']],
            array_map(self::historyLine(...), new SettingsRepository($this->connection())->history(null)),
        );

        $this->downloadCsv('settings-history-' . date('Y-m-d') . '.csv', ['Setting', 'From', 'To', 'When'], $lines);
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
            Flash::set('That form went stale. Try again.', FlashTone::Error);
            $this->bounce('/admin/content');

            return;
        }

        $slug = $this->request->body->str('slug');
        $kind = $this->request->body->str('kind');
        $action = $this->request->body->str('action');

        if (preg_match(self::SLUG, $slug) !== 1 || !in_array($kind, ['article', 'category'], true)) {
            Flash::set('Nothing changed. That was not a real category or article.', FlashTone::Error);
            $this->bounce('/admin/content');

            return;
        }

        $store = $kind === 'article'
            ? new ArticleRepository($this->connection())
            : new ArticleCategoryRepository($this->connection());

        $current = $kind === 'article'
            ? $store->forEditing($slug)
            : $store->forEditing($slug);

        $label = ucfirst($kind);
        $known = in_array($action, ['show', 'hide', 'up', 'down'], true);

        if ($current !== null && $known) {
            match ($action) {
                'show' => $store->setEnabled($slug, true),
                'hide' => $store->setEnabled($slug, false),
                // A swap with the neighbour rather than a step. Positions are
                // spaced 10, 20, 30 in the seeded data, so adding one to them
                // moved nothing and the button looked broken.
                'up' => $store->move($slug, -1),
                'down' => $store->move($slug, 1),
            };

            Flash::set(match ($action) {
                'show' => "{$label} shown.",
                'hide' => "{$label} hidden.",
                default => "{$label} moved.",
            });
        } else {
            Flash::set('Nothing changed.', FlashTone::Error);
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

        new AdminEventRepository($this->connection())
            ->record(AdminEventResource::Article, $posted['slug'], AdminEvent::Edited, $posted['title']);

        Flash::set($slug === null ? 'Article created.' : 'Article saved.');
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
            !in_array($posted['accent'], self::CATEGORY_ACCENTS, true)
                => 'Pick one of the colours the hub card actually has.',
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

        new AdminEventRepository($this->connection())
            ->record(AdminEventResource::Category, $posted['slug'], AdminEvent::Edited, $posted['title']);

        Flash::set($slug === null ? 'Category created.' : 'Category saved.');
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
            'accents' => self::CATEGORY_ACCENTS,
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
     * A CSV attachment, built with `fputcsv` on `php://temp` rather than
     * joined by hand -- it already knows how to quote a comma or a stray
     * quote in a passenger's name, which string concatenation does not
     * (G3.5, #308).
     *
     * Not wrapped in the public layout: `/admin/*\/export` is listed in
     * `Routes::EXCLUDE_HEADER_FOOTER_ROUTES`, the same as
     * `MyController::calendar()`'s `.ics` download.
     *
     * @param list<string> $header
     * @param list<list<string|int|null>> $rows
     */
    private function downloadCsv(string $filename, array $header, array $rows): void
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream for the export.');
        }

        // PHP 8.4 deprecated the implicit `\` escape character; named here
        // rather than left to the default so a row holding a literal `"`
        // still quotes the way it always has.
        fputcsv($stream, $header, escape: '\\');

        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo $csv === false ? '' : $csv;
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
