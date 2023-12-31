<?php

namespace TripBuilder\Controllers;

use GuzzleHttp\Exception\GuzzleException;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Helper;

class AjaxController extends AbstractController
{
    private array $get;

    /**
     * @return void
     * @throws GuzzleException
     */
    public function addTrip(): void
    {
        header('Content-type: application/json; charset=utf-8');

        $this->setGet([
            'flight_outbound' => $_GET['depart_id'] ?? null,
            'flight_return'   => $_GET['return_id'] ?? null,
        ]);

        if (! $this->get['flight_outbound']) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Wrong format'
            ]);

            return;
        }

        $apiClient = new Api(Config::get('api.fake.url'));

        $headers = [
            'Authorization' => Credentials::getBearer(),
            'Accept'        => 'application/json',
        ];

        $request = [
            'session_id' => session_id()
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

        $id = $this->db->insert('bookings', $request);

        if ($id) {
            $json = ['status' => 'success','message' => "Booking created with ID:\n" . Helper::bookingIdToString($id)];
        } else {
            $json = ['status' => 'error',  'message' => 'insert failed: ' . $this->db->getLastError()];
        }

        echo json_encode($json);
    }

    public function deleteBooking(): void
    {
        header('Content-type: application/json; charset=utf-8');

        $this->setGet([
            'booking_id' => $_GET['booking_id'] ?? null,
        ]);

        if (! $this->get['booking_id']) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Wrong format'
            ]);

            return;
        }

        $this->db->where('id', $this->get['booking_id']);
        $this->db->where('session_id', session_id());

        if ($this->db->delete('bookings')) {
            $json = [
                'status'  => 'success',
                'message' => sprintf('Booking %s was deleted', Helper::bookingIdToString($this->get['booking_id']))
            ];
        } else {
            $json = [
                'status'  => 'error',
                'message' => 'Booking delete failed: ' . $this->db->getLastError()
            ];
        }

        echo json_encode($json);
    }

    /**
     * @param $params
     * @return void
     */
    private function setGet($params): void
    {
        $this->get = $params;
    }

}
