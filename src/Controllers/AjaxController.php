<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Csrf;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Repository\RoutePriceRepository;
use TripBuilder\Repository\SubscriberRepository;
use TripBuilder\Service\FlightFinder;

class AjaxController extends AbstractController
{
    /** The longest address SMTP will carry, so anything longer was never deliverable. */
    private const int EMAIL_MAX = 254;

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

    /**
     * Reject anything that is not a same-origin POST carrying a valid CSRF token.
     */
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

        echo json_encode([
            'status' => 'ok',
            'cabin' => $cabin->value,
            'prices' => $prices->read($from, $to, $cabin, $since, $until),
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
     * Every answer says what actually happened. "Already on the list" is not an
     * error and is not dressed up as success either: somebody who cannot
     * remember whether they subscribed is precisely who needs telling.
     */
    public function subscribe(): void
    {
        header('Content-type: application/json; charset=utf-8');

        if (!$this->guardRequest()) {
            return;
        }

        $email = trim($this->request->body->str('email'));

        // Checked here and not only in the browser: the form is one way to
        // reach this, not the only one.
        if ($email === '' || mb_strlen($email) > self::EMAIL_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'That does not look like an email address.']);

            return;
        }

        try {
            $added = new SubscriberRepository($this->connection())->add($email);
        } catch (Throwable $e) {
            // The reason goes to the log, not to the page: a visitor cannot act
            // on it and a database error is not theirs to read.
            error_log('Subscribe failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'That did not work. Try again in a moment.']);

            return;
        }

        echo json_encode([
            'status' => 'ok',
            'added' => $added,
            'message' => $added
                ? 'Done. We will write when a fare drops.'
                : 'That address is already on the list.',
        ]);
    }

    private static function isCode(string $code): bool
    {
        return preg_match('/^[A-Z0-9]{3}$/', $code) === 1;
    }

    private function guardRequest(): bool
    {
        if (!$this->request->isPost()) {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);

            return false;
        }

        $token = $this->request->body->nullableStr('csrf_token')
            ?? $this->request->header('X-CSRF-Token');

        if (!Csrf::isValid($token)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing CSRF token']);

            return false;
        }

        return true;
    }

}
