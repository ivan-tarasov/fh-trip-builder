<?php

namespace TripBuilder\Controllers;

use GuzzleHttp\Exception\GuzzleException;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Debug\dBug;
use TripBuilder\Config;

class AjaxController extends AbstractController
{
    private array $get;

    /**
     * @return void
     * @throws GuzzleException
     */
    public function addTrip(): void
    {
        //header('Content-type: application/json; charset=utf-8');

        $this->setGet([
            'flight_a' => $_GET['depart_id'] ?? null,
            'flight_b' => $_GET['return_id'] ?? null,
        ]);

        if (! $this->get['flight_a']) {
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

            if ($field == 'flight_a') {
                $request['departure_time'] = $response->data->depart->date_time;
            }

            $request[$field] = json_encode($response->data);
        }

        $id = $this->db->insert('bookings', $request);

        if ($id) {
            $json = ['status' => 'success','message' => 'Trip added with id ' . $id];
        } else {
            $json = ['status' => 'error',  'message' => 'insert failed: ' . $this->db->getLastError()];
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
