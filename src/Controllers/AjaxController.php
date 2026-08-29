<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\Csrf;
use TripBuilder\Helper;
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
        $outboundIds = $this->parseIds($_POST['depart_ids'] ?? '');
        $returnIds = $this->parseIds($_POST['return_ids'] ?? '');

        if ($outboundIds === []) {
            echo json_encode(['status' => 'error', 'message' => 'Wrong format']);

            return;
        }

        $finder = new FlightFinder($this->connection());

        $outbound = $finder->findSegments($outboundIds);
        $return = $returnIds === [] ? [] : $finder->findSegments($returnIds);

        // Every requested leg must still resolve, or the itinerary is stale.
        if (count($outbound) !== count($outboundIds) || count($return) !== count($returnIds)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'The selected flight is no longer available.',
            ]);

            return;
        }

        $request = [
            'session_id' => session_id(),
            'departure_time' => $outbound[0]['depart']['date_time'],
            'flight_outbound' => json_encode($outbound),
            'flight_return' => $return === [] ? null : json_encode($return),
        ];

        try {
            $id = new BookingRepository($this->connection())->create($request);
            $json = ['status' => 'success', 'message' => "Booking created with ID:\n" . Helper::bookingIdToString($id)];
        } catch (Throwable $e) {
            error_log('Booking insert failed: ' . $e->getMessage());
            $json = ['status' => 'error', 'message' => 'Could not create the booking. Please try again later.'];
        }

        echo json_encode($json);
    }

    /**
     * Parse a comma-separated list of positive integer ids.
     *
     * @return list<int>
     */
    private function parseIds(string $csv): array
    {
        $ids = [];

        foreach (explode(',', $csv) as $part) {
            $id = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function deleteBooking(): void
    {
        header('Content-type: application/json; charset=utf-8');

        if (!$this->guardRequest()) {
            return;
        }

        $this->setGet([
            'booking_id' => filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
        ]);

        if (!$this->get['booking_id']) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Wrong format',
            ]);

            return;
        }

        try {
            $deleted = new BookingRepository($this->connection())
                ->deleteForSession($this->get['booking_id'], session_id());
        } catch (Throwable $e) {
            error_log('Booking delete failed: ' . $e->getMessage());
            $deleted = 0;
        }

        if ($deleted > 0) {
            $json = [
                'status' => 'success',
                'message' => sprintf('Booking %s was deleted', Helper::bookingIdToString($this->get['booking_id'])),
            ];
        } else {
            $json = [
                'status' => 'error',
                'message' => 'Booking not found or already deleted.',
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
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);

            return false;
        }

        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (!Csrf::isValid($token)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing CSRF token']);

            return false;
        }

        return true;
    }

}
