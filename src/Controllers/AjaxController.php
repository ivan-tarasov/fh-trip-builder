<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\ArticleRating;
use TripBuilder\BookingActor;
use TripBuilder\BookingEvent;
use TripBuilder\CabinClass;
use TripBuilder\Csrf;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\RateLimit;
use TripBuilder\Log;
use TripBuilder\Money;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Repository\ArticleVoteRepository;
use TripBuilder\Repository\BookingEventRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Repository\PostRepository;
use TripBuilder\Repository\PostVoteRepository;
use TripBuilder\Repository\RoutePriceRepository;
use TripBuilder\Repository\RouteWatchRepository;
use TripBuilder\Repository\SubscriberRepository;
use TripBuilder\Service\FlightFinder;
use TripBuilder\Voter;

class AjaxController extends AbstractController
{
    /** The longest address SMTP will carry, so anything longer was never deliverable. */
    private const int EMAIL_MAX = 254;

    /** Where a form post leaves its answer for the page it is sent back to. */
    private const string SUBSCRIBE_NOTICE = 'subscribe_notice';

    /** The same, for a vote cast with no scripting. */
    private const string VOTE_NOTICE = 'article_vote_notice';

    /** The same, for watching a route with no scripting. */
    private const string WATCH_ROUTE_NOTICE = 'route_watch_notice';

    /** Above the top of any real fare here -- a real price wide of this is a typo, not a threshold. */
    private const float THRESHOLD_MAX = 100000.0;

    /** @var array{booking_id: int} */
    private array $get;

    public function addTrip(): void
    {
        header('Content-type: application/json; charset=utf-8');

        if (!$this->guardRequest()) {
            return;
        }

        // Each direction is a comma-separated list of flight-leg ids (an
        // itinerary can have more than one leg when it connects).
        $body = $this->request->body;

        // Input::ids() rejects a list outright when any part will not parse,
        // where the parser here used to drop the bad part and carry on. A list
        // one leg short is a different itinerary, and the count check below
        // cannot tell the difference once the leg is gone.
        $outboundIds = $body->ids('depart_ids');
        $returnIds = $body->ids('return_ids');

        if ($outboundIds === []) {
            echo json_encode(['status' => 'error', 'message' => 'Wrong format']);

            return;
        }

        $finder = new FlightFinder($this->connection());

        // Same contract as /checkout: the cabin travels with the ids, because
        // they name the legs but not what was being bought.
        $cabin = CabinClass::fromRequest($body->nullableStr('class'));

        $outbound = $finder->findSegments($outboundIds, $cabin);
        $return = $returnIds === [] ? [] : $finder->findSegments($returnIds, $cabin);

        // Every requested leg must still resolve, or the itinerary is stale.
        if (count($outbound) !== count($outboundIds) || count($return) !== count($returnIds)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'The selected flight is no longer available.',
            ]);

            return;
        }

        // A booking now carries the passenger, the contact details and the card
        // it was paid with, none of which this endpoint ever asked for — the
        // columns are NOT NULL, so an insert from here cannot succeed. It used
        // to back the "Add this trip?" dialog; /checkout does that job now.
        //
        // Saying so beats failing through the catch below with "please try
        // again later", which would never come true.
        echo json_encode([
            'status' => 'error',
            'message' => 'Bookings are made at checkout now. Choose your flights and continue from there.',
            'checkout' => sprintf(
                '/checkout?depart_itin=%s%s',
                implode(',', $outboundIds),
                $returnIds === [] ? '' : '&return_itin=' . implode(',', $returnIds),
            ),
        ]);
    }


    /**
     * Cancel a booking. The row survives -- see BookingRepository::cancelForSession().
     */
    public function cancelBooking(): void
    {
        header('Content-type: application/json; charset=utf-8');

        if (!$this->guardRequest()) {
            return;
        }

        $this->setGet([
            // 0 for anything that is not a real id, which the guard below
            // reads as "wrong format". The lower bound matters: a bare int()
            // would accept -5 and pass it to the update.
            'booking_id' => $this->request->body->intWithin('booking_id', 0, 1, PHP_INT_MAX),
        ]);

        if (!$this->get['booking_id']) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Wrong format',
            ]);

            return;
        }

        try {
            $cancelled = new BookingRepository($this->connection())
                ->cancelForSession($this->get['booking_id'], $this->sessionId());
        } catch (Throwable $e) {
            Log::error('Booking cancel failed: ' . $e->getMessage());
            $cancelled = 0;
        }

        if ($cancelled > 0) {
            // Only when the update actually moved a row: `cancelForSession()`
            // matches on the session and refuses an already-cancelled booking,
            // so a zero here is a cancel that did not happen and must not be
            // logged as one (A3.8, #233).
            new BookingEventRepository($this->connection())->record(
                $this->get['booking_id'],
                BookingEvent::Cancelled,
                BookingActor::Visitor,
            );

            $json = [
                'status' => 'success',
                'message' => 'Booking cancelled',
            ];
        } else {
            $json = [
                'status' => 'error',
                'message' => 'Booking not found or already cancelled.',
            ];
        }

        echo json_encode($json);
    }

    /** @param array{booking_id: int} $params */
    private function setGet(array $params): void
    {
        $this->get = $params;
    }

    /**
     * How far ahead fares are precomputed for the calendar.
     *
     * **Not the horizon, and deliberately not.** It reads like a third copy of
     * `Horizon::DAYS` and is not one: the calendar stops at the horizon, but
     * this number is bounded by what a build costs. One route over the ninety
     * days that exist measured 13.3s, and the work scales with the flights in
     * range — so a year would be about four times that, per route, while a
     * visitor waits behind a lock.
     *
     * The comment here used to say this was "how far ahead the calendar can be
     * paged", which was never true: nothing capped the calendar at all until
     * E30 (#215). Days past this simply have no fare on them, which the picker
     * already handles — it draws a day with no price as a day with no price.
     */
    private const int PRICE_WINDOW_DAYS = 90;

    /** How old a route's prices may be before they are worked out again. */
    private const int PRICE_MAX_AGE_HOURS = 24;

    /**
     * The cheapest fare on each day of a route, for the calendar.
     *
     * POST and CSRF like the rest of /ajax, even though this only reads: a
     * cold route costs up to five seconds to work out, and an endpoint that
     * spends that much on behalf of any page that cares to ask is a cheap way
     * to load the machine.
     *
     * The answer is whatever is cached. A route nobody has opened before is
     * built here, which is why the browser asks for this after the calendar is
     * already on screen rather than before.
     *
     * Fares come back as base and tax rather than as one number, and the party
     * is applied in the browser. A child pays three quarters of the fare but a
     * whole adult's tax, so the two scale apart -- and keeping them apart means
     * changing the passengers is arithmetic on what is already there instead of
     * another ten builds of the same route.
     */
    public function dayPrices(): void
    {
        header('Content-type: application/json; charset=utf-8');

        if (!$this->guardRequest()) {
            return;
        }

        $from = strtoupper($this->request->body->str('from'));
        $to = strtoupper($this->request->body->str('to'));

        if (!self::isCode($from) || !self::isCode($to) || $from === $to) {
            http_response_code(HttpStatus::BadRequest->value);
            echo json_encode(['status' => 'error', 'message' => 'Wrong format']);

            return;
        }

        // The cabin belongs in the answer: the cheapest business day is not the
        // cheapest economy day, because the uplift scales with haul and not
        // every flight sells every cabin.
        $cabin = CabinClass::fromRequest($this->request->body->nullableStr('class'));

        $prices = new RoutePriceRepository($this->connection());
        $since = date('Y-m-d');
        $until = date('Y-m-d', strtotime('+' . self::PRICE_WINDOW_DAYS . ' day'));

        if ($prices->isStale($from, $to, $cabin, self::PRICE_MAX_AGE_HOURS)) {
            $this->buildOnce($from, $to, $cabin, $since, $until, $prices);
        }

        // Converted here, not in the browser. The calendar formats its own
        // cells, so handing it Canadian dollars and a yen symbol -- which is
        // what passing only the symbol through would have done -- puts a ¥ in
        // front of a dollar figure on thirty cells at once.
        //
        // The conversion happens on read, *after* the cache: the expensive
        // build is keyed on from/to/cabin and guarded by a lock, and adding a
        // currency to either would serialise unrelated requests and multiply
        // the table by thirty for figures that are one multiplication apart.
        $money = Money::active();
        $currency = $money->currency();

        echo json_encode([
            'status' => 'ok',
            'cabin' => $cabin->value,
            // The payload says what its own numbers are. The cookie can change
            // between the page rendering and this request arriving, and a
            // calendar that took the symbol from the page and the figures from
            // here would then disagree with itself.
            'currency' => [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'before' => $currency->symbolFirst,
                'group' => $currency->group,
            ],
            'prices' => array_map(
                static fn(array $day): array => [
                    'base' => $money->convert($day['base']),
                    'tax' => $money->convert($day['tax']),
                ],
                $prices->read($from, $to, $cabin, $since, $until),
            ),
        ]);
    }

    /**
     * Work the route out, unless somebody else already is.
     *
     * Two people opening the same cold calendar would otherwise each spend the
     * same five seconds on the same answer. The one who gets the lock pays; the
     * other is served whatever is already there and picks the rest up next time.
     */
    private function buildOnce(
        string $from,
        string $to,
        CabinClass $cabin,
        string $since,
        string $until,
        RoutePriceRepository $prices,
    ): void {
        $name = 'route_prices_' . $from . '_' . $to . '_' . $cabin->value;
        $connection = $this->connection();

        /** @var int|null $acquired */
        $acquired = $connection->fetchValue('SELECT GET_LOCK(?, 0)', [$name], 0);

        if ($acquired !== 1) {
            return;
        }

        try {
            $prices->build($from, $to, $cabin, $since, $until);
        } finally {
            $connection->fetchValue('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    /**
     * Put an address on the fare-alert list.
     *
     * The footer carried a sign-up once and it was removed, because it took
     * what somebody typed and dropped it -- there was nowhere to put an
     * address. There is now, and this is the other half.
     *
     * Reachable two ways. The footer's script sends fetch and asks for JSON;
     * with scripting off the same form posts itself and the browser wants a
     * page. Answering JSON to a browser that has navigated puts a wall of
     * braces on screen where the site used to be, so that case is sent back to
     * the page it came from carrying the message in the session.
     *
     * Every answer says what actually happened. "Already on the list" is not an
     * error and is not dressed up as success either: somebody who cannot
     * remember whether they subscribed is precisely who needs telling.
     */
    public function subscribe(): void
    {
        $asJson = str_contains((string) $this->request->header('Accept'), 'application/json');

        if ($failure = $this->guardFailure(RateLimit::Subscribe)) {
            [$code, $message] = $failure;
            $this->answerSubscribe($asJson, $code, ['status' => 'error', 'message' => $message], 'bad');

            return;
        }

        $email = trim($this->request->body->str('email'));

        // Checked here and not only in the browser: the form is one way to
        // reach this, not the only one.
        if ($email === '' || mb_strlen($email) > self::EMAIL_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->answerSubscribe($asJson, HttpStatus::UnprocessableEntity, [
                'status' => 'error',
                'message' => 'That does not look like an email address.',
            ], 'bad');

            return;
        }

        try {
            $added = new SubscriberRepository($this->connection())->add($email);
        } catch (Throwable $e) {
            // The reason goes to the log, not to the page: a visitor cannot act
            // on it and a database error is not theirs to read.
            Log::error('Subscribe failed: ' . $e->getMessage());
            $this->answerSubscribe($asJson, HttpStatus::InternalServerError, [
                'status' => 'error',
                'message' => 'That did not work. Try again in a moment.',
            ], 'bad');

            return;
        }

        $this->answerSubscribe($asJson, HttpStatus::Ok, [
            'status' => 'ok',
            'added' => $added,
            // "We will write when a fare drops" was the strongest promise in
            // the app and the last one standing: there is no mailer here, so
            // nothing writes to anybody. The list is real; the sending is not.
            'message' => $added
                ? 'Done. You are on the list.'
                : 'That address is already on the list.',
        ], $added ? 'good' : 'quiet');
    }

    /**
     * Watch one route for a price, from the form on its own page (C6, #155).
     *
     * Economy only and no cabin field: the route page itself is always
     * `CabinClass::Economy` (`RouteController`), so offering a choice the
     * page cannot back up would be a lie the form told on its behalf.
     *
     * Registering twice for the same address and route updates the threshold
     * rather than adding a second watch -- `RouteWatchRepository::subscribe()`'s
     * own upsert, the same reasoning `subscribers.email` being UNIQUE
     * already gives the general list.
     */
    public function watchRoute(): void
    {
        $asJson = str_contains((string) $this->request->header('Accept'), 'application/json');

        if ($failure = $this->guardFailure(RateLimit::WatchRoute)) {
            [$code, $message] = $failure;
            $this->answerWatchRoute($asJson, $code, ['status' => 'error', 'message' => $message], 'bad');

            return;
        }

        $email = trim($this->request->body->str('email'));
        $from = strtoupper($this->request->body->str('from'));
        $to = strtoupper($this->request->body->str('to'));
        $threshold = filter_var($this->request->body->str('threshold'), FILTER_VALIDATE_FLOAT);

        // Checked here and not only in the browser: the form is one way to
        // reach this, not the only one.
        $error = match (true) {
            $email === '' || mb_strlen($email) > self::EMAIL_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)
                => 'That does not look like an email address.',
            !self::isCode($from) || !self::isCode($to) || $from === $to
                => 'That does not look like a real route.',
            $threshold === false || $threshold <= 0 || $threshold > self::THRESHOLD_MAX
                => 'Give a real price to watch for.',
            default => null,
        };

        if ($error !== null) {
            $this->answerWatchRoute($asJson, HttpStatus::UnprocessableEntity, [
                'status' => 'error',
                'message' => $error,
            ], 'bad');

            return;
        }

        try {
            new RouteWatchRepository($this->connection())
                ->subscribe($email, $from, $to, CabinClass::Economy, (float) $threshold);
        } catch (Throwable $e) {
            // The reason goes to the log, not to the page: a visitor cannot act
            // on it and a database error is not theirs to read.
            Log::error('Watch route failed: ' . $e->getMessage());
            $this->answerWatchRoute($asJson, HttpStatus::InternalServerError, [
                'status' => 'error',
                'message' => 'That did not work. Try again in a moment.',
            ], 'bad');

            return;
        }

        $this->answerWatchRoute($asJson, HttpStatus::Ok, [
            'status' => 'ok',
            'message' => sprintf(
                "Done. We'll email %s if %s to %s drops under %s CAD.",
                $email,
                $from,
                $to,
                number_format((float) $threshold, 2),
            ),
        ], 'good');
    }

    /**
     * Record whether an article helped, from the thumbs on a help page.
     *
     * Reachable two ways, like /ajax/subscribe and for the same reason: the
     * page's script sends fetch and asks for JSON, and with scripting off the
     * same form posts itself and the browser wants a page back.
     *
     * Nothing here trusts the form. The slug has to be one we publish, the
     * verdict has to be one of two numbers, and the voter is a cookie this
     * endpoint mints rather than anything the request can name -- otherwise a
     * posted `voter` field would let one person vote as many times as they can
     * invent tokens.
     */
    public function articleVote(): void
    {
        $asJson = str_contains((string) $this->request->header('Accept'), 'application/json');

        if ($failure = $this->guardFailure(RateLimit::Vote)) {
            [$code, $message] = $failure;
            $this->answerVote($asJson, $code, ['status' => 'error', 'message' => $message], 'bad');

            return;
        }

        $slug = $this->request->body->str('slug');

        // An exact key in the catalogue, on Currency::tryFrom()'s reasoning: a
        // slug is whatever was posted, so `Baggage` is somebody editing the
        // form by hand. Without this the table fills with votes for articles
        // that do not exist, and `varchar(64)` would take most of them.
        //
        // Case-sensitive, unlike HelpController, which lower-cases and 301s.
        // A reader following a capitalised link gets redirected to the real
        // page; a script posting `slug=Baggage` gets refused. Deliberate.
        //
        // Reading the articles table means this can now fail, and a failure
        // has to refuse rather than wave the vote through -- an allow-list
        // that opens when the database is down is not one.
        try {
            $known = new ArticleRepository($this->connection())->all();
        } catch (Throwable $e) {
            Log::error('Article vote allow-list unavailable: ' . $e->getMessage());
            $this->answerVote($asJson, HttpStatus::InternalServerError, [
                'status' => 'error',
                'message' => 'That did not work. Try again in a moment.',
            ], 'bad');

            return;
        }

        if (!array_key_exists($slug, $known)) {
            $this->answerVote($asJson, HttpStatus::UnprocessableEntity, [
                'status' => 'error',
                'message' => 'That is not an article we have.',
            ], 'bad');

            return;
        }

        // -1 for missing, not a number, or anything but 0 and 1. filter_var
        // does that inside intWithin, which matters: a plain (int) cast would
        // turn "yes" into 0 and record an unreadable answer as a thumbs down.
        $helpful = $this->request->body->intWithin('helpful', -1, 0, 1);

        if ($helpful < 0) {
            $this->answerVote($asJson, HttpStatus::UnprocessableEntity, [
                'status' => 'error',
                'message' => 'Say whether it helped or it did not.',
            ], 'bad');

            return;
        }

        try {
            $repository = new ArticleVoteRepository($this->connection());
            // Minted here and nowhere else, so reading an article tags nobody.
            $repository->record($slug, Voter::identify($this->request->isSecure()), $helpful === 1);
            $tally = $repository->tallyFor($slug);
        } catch (Throwable $e) {
            // The reason goes to the log, not to the page, as with subscribe:
            // a visitor cannot act on it and a database error is not theirs.
            Log::error('Article vote failed: ' . $e->getMessage());
            $this->answerVote($asJson, HttpStatus::InternalServerError, [
                'status' => 'error',
                'message' => 'That did not work. Try again in a moment.',
            ], 'bad');

            return;
        }

        $this->answerVote($asJson, HttpStatus::Ok, [
            'status' => 'ok',
            'helpful' => $helpful === 1,
            'votes' => $tally['votes'],
            'yes' => $tally['helpful'],
            // Whether the page may print the figures yet. Decided here so the
            // browser and a no-script render cannot disagree about it.
            'shown' => ArticleRating::worthShowing($tally['votes']),
            // No undertaking to act on it. There is nobody here to read a
            // thumbs-down, and saying otherwise would be the kind of promise
            // PromisesTest exists to keep out of this app.
            'message' => $helpful === 1 ? 'Thanks. Glad it helped.' : 'Thanks. Noted that it did not.',
        ], $helpful === 1 ? 'good' : 'quiet');
    }

    /**
     * The same endpoint for an Airside post.
     *
     * A copy of the one above rather than a shared method with a flag, because
     * the two differ in the one place that matters: the allow-list. An article
     * vote checks `articles` and a post vote checks `posts`, and a single
     * endpoint taking a "which table" parameter from the request would be an
     * allow-list a caller chooses -- which is not one.
     *
     * Everything else is shared and stays shared: the session notice, the
     * `#article-verdict` fragment the redirect lands on, the script, and the
     * threshold below which no figures are printed.
     */
    public function postVote(): void
    {
        $asJson = str_contains((string) $this->request->header('Accept'), 'application/json');

        if ($failure = $this->guardFailure(RateLimit::Vote)) {
            [$code, $message] = $failure;
            $this->answerVote($asJson, $code, ['status' => 'error', 'message' => $message], 'bad');

            return;
        }

        $slug = $this->request->body->str('slug');

        // An exact key in the catalogue, on Currency::tryFrom()'s reasoning: a
        // slug is whatever was posted, so `Baggage` is somebody editing the
        // form by hand. Without this the table fills with votes for articles
        // that do not exist, and `varchar(64)` would take most of them.
        //
        // Case-sensitive, unlike HelpController, which lower-cases and 301s.
        // A reader following a capitalised link gets redirected to the real
        // page; a script posting `slug=Baggage` gets refused. Deliberate.
        //
        // Reading the articles table means this can now fail, and a failure
        // has to refuse rather than wave the vote through -- an allow-list
        // that opens when the database is down is not one.
        try {
            $known = new PostRepository($this->connection())->all();
        } catch (Throwable $e) {
            Log::error('Post vote allow-list unavailable: ' . $e->getMessage());
            $this->answerVote($asJson, HttpStatus::InternalServerError, [
                'status' => 'error',
                'message' => 'That did not work. Try again in a moment.',
            ], 'bad');

            return;
        }

        if (!array_key_exists($slug, $known)) {
            $this->answerVote($asJson, HttpStatus::UnprocessableEntity, [
                'status' => 'error',
                'message' => 'That is not an article we have.',
            ], 'bad');

            return;
        }

        // -1 for missing, not a number, or anything but 0 and 1. filter_var
        // does that inside intWithin, which matters: a plain (int) cast would
        // turn "yes" into 0 and record an unreadable answer as a thumbs down.
        $helpful = $this->request->body->intWithin('helpful', -1, 0, 1);

        if ($helpful < 0) {
            $this->answerVote($asJson, HttpStatus::UnprocessableEntity, [
                'status' => 'error',
                'message' => 'Say whether it helped or it did not.',
            ], 'bad');

            return;
        }

        try {
            $repository = new PostVoteRepository($this->connection());
            // Minted here and nowhere else, so reading an article tags nobody.
            $repository->record($slug, Voter::identify($this->request->isSecure()), $helpful === 1);
            $tally = $repository->tallyFor($slug);
        } catch (Throwable $e) {
            // The reason goes to the log, not to the page, as with subscribe:
            // a visitor cannot act on it and a database error is not theirs.
            Log::error('Post vote failed: ' . $e->getMessage());
            $this->answerVote($asJson, HttpStatus::InternalServerError, [
                'status' => 'error',
                'message' => 'That did not work. Try again in a moment.',
            ], 'bad');

            return;
        }

        $this->answerVote($asJson, HttpStatus::Ok, [
            'status' => 'ok',
            'helpful' => $helpful === 1,
            'votes' => $tally['votes'],
            'yes' => $tally['helpful'],
            // Whether the page may print the figures yet. Decided here so the
            // browser and a no-script render cannot disagree about it.
            'shown' => ArticleRating::worthShowing($tally['votes']),
            // No undertaking to act on it. There is nobody here to read a
            // thumbs-down, and saying otherwise would be the kind of promise
            // PromisesTest exists to keep out of this app.
            'message' => $helpful === 1 ? 'Thanks. Glad it was worth it.' : 'Thanks. Noted that it was not.',
        ], $helpful === 1 ? 'good' : 'quiet');
    }

    /**
     * JSON to a script, the page back to a browser.
     *
     * The same fork as answerSubscribe() with its own session key and anchor.
     * Left as two short methods rather than one with six arguments; what must
     * not be duplicated is returnTo(), and it is not.
     *
     * @param array{message: string, ...} $payload
     */
    private function answerVote(bool $asJson, HttpStatus $code, array $payload, string $tone): void
    {
        if ($asJson) {
            header('Content-type: application/json; charset=utf-8');
            http_response_code($code->value);
            echo json_encode($payload);

            return;
        }

        $_SESSION[self::VOTE_NOTICE] = [
            'tone' => $tone,
            'message' => $payload['message'],
        ];

        // Back to the article, at the block that was just used, and a 303 so
        // the back button does not offer to send the vote again.
        $this->bounce($this->returnTo() . '#article-verdict', HttpStatus::SeeOther);
    }

    /**
     * JSON to a script, the page back to a browser.
     *
     * @param array{message: string, ...} $payload
     */
    private function answerSubscribe(bool $asJson, HttpStatus $code, array $payload, string $tone): void
    {
        if ($asJson) {
            header('Content-type: application/json; charset=utf-8');
            http_response_code($code->value);
            echo json_encode($payload);

            return;
        }

        // Carried in the session rather than in the URL: a query string would
        // survive a refresh and go on announcing a sign-up that happened once,
        // and it would be there to be shared by anybody copying the address.
        $_SESSION[self::SUBSCRIBE_NOTICE] = [
            'tone' => $tone,
            'message' => $payload['message'],
        ];

        // Redirect rather than render: a POST left in history is a POST the
        // browser offers to send again on every back button.
        $this->bounce($this->returnTo() . '#fare-alerts', HttpStatus::SeeOther);
    }

    /** @param array{status: string, message: string} $payload */
    private function answerWatchRoute(bool $asJson, HttpStatus $code, array $payload, string $tone): void
    {
        if ($asJson) {
            header('Content-type: application/json; charset=utf-8');
            http_response_code($code->value);
            echo json_encode($payload);

            return;
        }

        $_SESSION[self::WATCH_ROUTE_NOTICE] = [
            'tone' => $tone,
            'message' => $payload['message'],
        ];

        $this->bounce($this->returnTo() . '#watch-route', HttpStatus::SeeOther);
    }

    /**
     * Where to send a browser back to after a form post.
     *
     * The form names its own page in a hidden field. Whatever comes back is
     * treated as hostile until it looks like one of our paths: it has to begin
     * with a single slash, which rules out `//evil.example` and any absolute
     * URL, or this endpoint would forward anybody anywhere.
     *
     * Shared by every form post here rather than copied per endpoint. One
     * spelling of this check is the point: two would drift, and the comment
     * below is what a drifted second copy would be missing.
     */
    private function returnTo(): string
    {
        $to = (string) $this->request->body->nullableStr('return_to');

        // Spelled out rather than pattern-matched. The regex this replaced read
        // `[^\\\s]`, which single-quoting collapsed into "not a backslash and
        // not the letter s" -- so it turned away every path with an s in it and
        // waved through one with a space.
        $ours = str_starts_with($to, '/')
            // `//host` and `/\host` are both absolute to a browser.
            && !str_starts_with($to, '//')
            && strpbrk($to, "\\\r\n\t ") === false;

        return $ours ? $to : '/';
    }

    private static function isCode(string $code): bool
    {
        return preg_match('/^[A-Z0-9]{3}$/', $code) === 1;
    }

    /**
     * Why this request may not proceed, or null when it may.
     *
     * Separate from guardRequest() because /ajax/subscribe can be reached by a
     * plain form as well as by fetch, and a browser that has followed a form
     * wants a page back rather than the JSON every other endpoint here answers
     * with. The checks belong in one place; only the way they are reported
     * differs.
     *
     * @param RateLimit|null $limit the allowance this endpoint spends from, or
     *     null for the ones that only build a trip in the session
     *
     * @return array{HttpStatus, string}|null
     */
    private function guardFailure(?RateLimit $limit = null): ?array
    {
        if (!$this->request->isPost()) {
            return [HttpStatus::MethodNotAllowed, 'Method not allowed'];
        }

        // Csrf::FIELD and Csrf::HEADER, not the strings they happen to hold.
        // This read used to spell both out, and the field it spelled was
        // 'csrf_token' -- which is the *session key's* name, not the field's.
        // So the constant said one thing, checkout obeyed it, and everything
        // posting to /ajax obeyed something else. Nothing was broken by it;
        // renaming the constant would have been.
        $token = $this->request->body->nullableStr(Csrf::FIELD)
            ?? $this->request->header(Csrf::HEADER);

        if (!Csrf::isValid($token)) {
            return [HttpStatus::Forbidden, 'Invalid or missing CSRF token'];
        }

        // After the token and not before it. A 429 that arrived first would
        // answer a different question than it looks like -- whether the token
        // was accepted -- and would do it without spending one.
        if ($limit !== null && $this->isOverLimit($limit)) {
            return [HttpStatus::TooManyRequests, $limit->refusal()];
        }

        return null;
    }

    /** Reject anything that is not a same-origin POST carrying a valid CSRF token. */
    private function guardRequest(): bool
    {
        $failure = $this->guardFailure();

        if ($failure === null) {
            return true;
        }

        [$code, $message] = $failure;

        http_response_code($code->value);
        echo json_encode(['status' => 'error', 'message' => $message]);

        return false;
    }

}
