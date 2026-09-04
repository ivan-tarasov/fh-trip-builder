<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Csrf;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Service\FlightFinder;

class AjaxController extends AbstractController
{
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
