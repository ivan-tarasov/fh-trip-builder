<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\ArticleRating;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Csrf;
use TripBuilder\Money;
use TripBuilder\Repository\ArticleVoteRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Repository\RoutePriceRepository;
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
                ->cancelForSession($this->get['booking_id'], session_id());
        } catch (Throwable $e) {
            error_log('Booking cancel failed: ' . $e->getMessage());
            $cancelled = 0;
        }

        if ($cancelled > 0) {
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

    private function setGet(array $params): void
    {
        $this->get = $params;
    }

    /** How far ahead the calendar can be paged, and so how far a build looks. */
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
            http_response_code(400);
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

        if ((int) $connection->fetchValue('SELECT GET_LOCK(?, 0)', [$name], 0) !== 1) {
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

        if ($failure = $this->guardFailure()) {
            [$code, $message] = $failure;
            $this->answerSubscribe($asJson, $code, ['status' => 'error', 'message' => $message], 'bad');

            return;
        }

        $email = trim($this->request->body->str('email'));

        // Checked here and not only in the browser: the form is one way to
        // reach this, not the only one.
        if ($email === '' || mb_strlen($email) > self::EMAIL_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->answerSubscribe($asJson, 422, [
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
            error_log('Subscribe failed: ' . $e->getMessage());
            $this->answerSubscribe($asJson, 500, [
                'status' => 'error',
                'message' => 'That did not work. Try again in a moment.',
            ], 'bad');

            return;
        }

        $this->answerSubscribe($asJson, 200, [
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

        if ($failure = $this->guardFailure()) {
            [$code, $message] = $failure;
            $this->answerVote($asJson, $code, ['status' => 'error', 'message' => $message], 'bad');

            return;
        }

        $slug = $this->request->body->str('slug');

        // An exact key in the catalogue, on Currency::tryFrom()'s reasoning: a
        // slug is whatever was posted, so `Baggage` is somebody editing the
        // form by hand. Without this the table fills with votes for articles
        // that do not exist, and `varchar(64)` would take most of them.
        if (!array_key_exists($slug, (array) Config::get('help.articles', []))) {
            $this->answerVote($asJson, 422, [
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
            $this->answerVote($asJson, 422, [
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
            error_log('Article vote failed: ' . $e->getMessage());
            $this->answerVote($asJson, 500, [
                'status' => 'error',
                'message' => 'That did not work. Try again in a moment.',
            ], 'bad');

            return;
        }

        $this->answerVote($asJson, 200, [
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
     * JSON to a script, the page back to a browser.
     *
     * The same fork as answerSubscribe() with its own session key and anchor.
     * Left as two short methods rather than one with six arguments; what must
     * not be duplicated is returnTo(), and it is not.
     *
     * @param array<string, mixed> $payload
     */
    private function answerVote(bool $asJson, int $code, array $payload, string $tone): void
    {
        if ($asJson) {
            header('Content-type: application/json; charset=utf-8');
            http_response_code($code);
            echo json_encode($payload);

            return;
        }

        $_SESSION[self::VOTE_NOTICE] = [
            'tone' => $tone,
            'message' => (string) $payload['message'],
        ];

        // Back to the article, at the block that was just used, and a 303 so
        // the back button does not offer to send the vote again.
        $this->bounce($this->returnTo() . '#article-verdict', 303);
    }

    /**
     * JSON to a script, the page back to a browser.
     *
     * @param array<string, mixed> $payload
     */
    private function answerSubscribe(bool $asJson, int $code, array $payload, string $tone): void
    {
        if ($asJson) {
            header('Content-type: application/json; charset=utf-8');
            http_response_code($code);
            echo json_encode($payload);

            return;
        }

        // Carried in the session rather than in the URL: a query string would
        // survive a refresh and go on announcing a sign-up that happened once,
        // and it would be there to be shared by anybody copying the address.
        $_SESSION[self::SUBSCRIBE_NOTICE] = [
            'tone' => $tone,
            'message' => (string) $payload['message'],
        ];

        // Redirect rather than render: a POST left in history is a POST the
        // browser offers to send again on every back button.
        $this->bounce($this->returnTo() . '#fare-alerts', 303);
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
     * @return array{int, string}|null
     */
    private function guardFailure(): ?array
    {
        if (!$this->request->isPost()) {
            return [405, 'Method not allowed'];
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
            return [403, 'Invalid or missing CSRF token'];
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

        http_response_code($code);
        echo json_encode(['status' => 'error', 'message' => $message]);

        return false;
    }

}
