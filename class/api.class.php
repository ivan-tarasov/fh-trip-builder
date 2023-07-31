<?php
/**
 * TripBuilder API connector
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.2.6
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__FILE__, 2));
}

require_once ROOT_PATH . '/vendor/Unirest.php';

class APIConnector
{
    private $_token;
    private $_api;

    function __construct($url, $token)
    {
        $this->_api   = $url;
        $this->_token = $token;
    }


    /**
     * @param $method
     * @param $body
     * @param $type
     * @return array|mixed
     */
    private function sendRequest($method, $body = [], $type = 'get')
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-Auth-Token' => $this->_token
        ];

        switch ($type) {
            case 'get':
                $data = Unirest\Request::get(
                    $this->_api . $method,
                    $headers,
                    $body
                );

                $_SESSION['api_call_count'] = !empty($_SESSION['api_call_count'])
                    ? ++$_SESSION['api_call_count']
                    : 1;

                break;
            case 'post':
                $body = json_encode($body);

                $data = Unirest\Request::post(
                    $this->_api . $method,
                    $headers,
                    $body
                );

                break;
            default:
                $data = Unirest\Request::get(
                    $this->_api . $method . '&api_key=' . $this->_token . '&' . $body,
                    $headers,
                    ''
                );
        }

        if (isset($data->headers['TRIPBUILDER-ERROR-MESSAGE'])) {
            return [
                'type' => 'error',
                'info' => $data->headers['TRIPBUILDER-ERROR-MESSAGE']
            ];
        } else {
            return json_decode($data->raw_body, 1);
        }

    }

    public function getAllAirlines()
    {
        return $this->sendRequest('/airlines/');
    }

    public function getAllAirports()
    {
        return $this->sendRequest('/airports/');
    }

    /**
     * @param $from
     * @param $to
     * @param $departure_date
     * @param $per_page
     * @param $current_page
     * @return array|mixed
     */
    public function getFlights($from, $to, $departure_date, $per_page = null, $current_page = null)
    {
        $input_data = [
            'from'                 => $from,
            'to'                   => $to,
            'departure_date_start' => @$departure_date['start'],
            'departure_date_end'   => @$departure_date['end'],
            'per_page'             => $per_page,
            'current_page'         => $current_page,
            'SESSION_DATA'         => $_SESSION
        ];

        return $this->sendRequest('/flights/', $input_data);
    }

}
