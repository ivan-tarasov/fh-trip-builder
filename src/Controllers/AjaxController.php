<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use GuzzleHttp\Exception\GuzzleException;
use Throwable;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Config;
use TripBuilder\Csrf;
use TripBuilder\Helper;
use TripBuilder\Repository\BookingRepository;

class AjaxController extends AbstractController
{
    private array $get;

    /**
     * @throws GuzzleException
     */
    public function addTrip(): void
    {
        header('Content-type: application/json; charset=utf-8');

        if (!$this->guardRequest()) {
            return;
        }

        $this->setGet([
            'flight_outbound' => filter_var($_POST['depart_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
            'flight_return' => filter_var($_POST['return_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
        ]);

        if (!$this->get['flight_outbound']) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Wrong format',
            ]);

            return;
        }

        $apiClient = new Api(Config::get('api.fake.url'));

        $headers = [
            'Authorization' => Credentials::getBearer(),
            'Accept' => 'application/json',
        ];

        $request = [
            'session_id' => session_id(),
        ];

        foreach ($this->get as $field => $flight_id) {
            if (empty($flight_id)) {
                $request[$field] = null;

                continue;
            }

            $response = $apiClient->post('flights/one', $headers, ['id' => $flight_id,]);

            if ($field == 'flight_outbound') {
                $request['departure_time'] = $response->data->depart->date_time;
            }

            $request[$field] = json_encode($response->data);
        }

        try {
            $id = new BookingRepository($this->connection())->create($request);
            $json = ['status' => 'success', 'message' => "Booking created with ID:\n" . Helper::bookingIdToString($id)];
        } catch (Throwable $e) {
            error_log('Booking insert failed: ' . $e->getMessage());
            $json = ['status' => 'error', 'message' => 'Could not create the booking. Please try again later.'];
        }

        echo json_encode($json);
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
