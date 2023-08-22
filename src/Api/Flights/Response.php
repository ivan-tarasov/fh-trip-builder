<?php

namespace TripBuilder\Api\Flights;

use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\HttpException;
use TripBuilder\Debug\dBug;

class Response extends AbstractApi
{
    /**
     * @var string Data array keys
     */
    const DATA_TRIPTYPE    = 'trip_type',
          DATA_DEPART      = 'from',
          DATA_ARRIVE      = 'to',
          DATA_DEPART_DATE = 'depart_date',
          DATA_RETURN_DATE = 'return_date',
          DATA_ADULT_COUNT = 'adult_count',
          DATA_CHILD_COUNT = 'child_count';

    /**
     * @var string Trip types
     */
    const TRIPTYPE_ROUNDTRIP = 'roundtrip',
          TRIPTYPE_ONEWAY    = 'oneway';

    const RESPONSE_OUTBOUND        = 'outbound',
          RESPONSE_RETURNING       = 'returning',
          RESPONSE_DEPART          = 'depart',
          RESPONSE_ARRIVE          = 'arrive',
          RESPONSE_FLIGHT_NUMBER   = 'number',
          RESPONSE_AIRPORT_CODE    = 'airport_code',
          RESPONSE_AIRPORT_NAME    = 'airport_name',
          RESPONSE_AIRPORT_COUNTRY = 'airport_country',
          RESPONSE_AIRPORT_CITY    = 'airport_city',
          RESPONSE_DATE_TIME       = 'dateTime',
          RESPONSE_FLIGHT_CARRIER  = 'carrier',
          RESPONSE_CABIN_CODE      = 'cabin_code',
          RESPONSE_DISTANCE        = 'distance',
          RESPONSE_DURATION        = 'duration',
          RESPONSE_PRICE_BASE      = 'price_base',
          RESPONSE_PRICE_TAX       = 'price_tax',
          RESPONSE_RATING          = 'rating';

    private string $from;

    private string $to;

    private string $departDate;

    private string $returnDate;

    private int $adultNum;

    private int $childNum;

    /**
     * @throws \Exception
     */
    public function get(): void
    {
        // Throw Bad Request Exception if data or one of necessary params is empty
        if (empty($this->data)
            || empty($this->data[self::DATA_TRIPTYPE])
            || empty($this->data[self::DATA_DEPART])
            || empty($this->data[self::DATA_ARRIVE])
            || empty($this->data[self::DATA_DEPART_DATE])
            || empty($this->data[self::DATA_ADULT_COUNT])
        ) {
             HttpException::badRequest();
        }

        $this->setFrom($this->data[self::DATA_DEPART])
            ->setTo($this->data[self::DATA_ARRIVE])
            ->setDepartDate($this->data[self::DATA_DEPART_DATE])
            ->setReturnDate($this->data[self::DATA_RETURN_DATE] ?: '')
            ->setAdultNum($this->data[self::DATA_ADULT_COUNT])
            ->setChildNum($this->data[self::DATA_CHILD_COUNT]);

        $flights = match ($this->data[self::DATA_TRIPTYPE]) {
            self::TRIPTYPE_ONEWAY    => $this->getOnewayFlights(),
            self::TRIPTYPE_ROUNDTRIP => $this->getRoundtripFlights(),
            default => ['error' => 'Wrong trip type'],
        };

        $this->sendResponse(200, [
            'triptype' => $this->data[self::DATA_TRIPTYPE],
            'count'    => $this->db->count,
            'flights'  => $flights
        ]);
    }

    /**
     * @return array
     */
    private function getOnewayFlights(): array
    {
        $columns = [
            'flight.id                     AS flight_id',
            'flight.airline                AS flight_airline_code',
            'airline.title                 AS flight_airline_title',
            'flight.number                 AS flight_number',
            'depart_airport.code           AS departure_airport_code',
            'depart_airport.title          AS departure_airport_title',
            'depart_country.title          AS departure_airport_country',
            'depart_airport.city           AS departure_airport_city',
            'flight.departure_time         AS departure_time',
            'depart_airport.timezone_name  AS departure_airport_timezone',
            'arrive_airport.code           AS arrival_airport_code',
            'arrive_airport.title          AS arrival_airport_title',
            'arrive_country.title          AS arrival_airport_country',
            'arrive_airport.city           AS arrival_airport_city',
            'flight.arrival_time           AS arrival_time',
            'arrive_airport.timezone_name  AS arrival_airport_timezone',
            'flight.distance               AS flight_distance',
            'flight.duration               AS flight_duration',
            'flight.price_base             AS flight_price_base',
            'flight.price_tax              AS flight_price_tax',
            'flight.rating                 AS flight_rating',
        ];

        $this->db->join('airports depart_airport',  'flight.departure_airport = depart_airport.code');
        $this->db->join('airports arrive_airport',  'flight.arrival_airport = arrive_airport.code');
        $this->db->join('airlines airline',         'flight.airline = airline.code');
        $this->db->join('countries depart_country', 'depart_airport.country_code = depart_country.code');
        $this->db->join('countries arrive_country', 'arrive_airport.country_code = arrive_country.code');

        $this->db->where('(depart_airport.code = ? or depart_airport.city_code = ?)', array_fill(0, 2, $this->from));
        $this->db->where('(arrive_airport.code = ? or arrive_airport.city_code = ?)', array_fill(0, 2, $this->to));
        $this->db->where('DATE(flight.departure_time)', $this->departDate);

        $flights = $this->db->get('flights flight', null, $columns);

        return array_map(function($flight) {
            return [
                self::RESPONSE_PRICE_BASE => (float) $flight['flight_price_base'],
                self::RESPONSE_PRICE_TAX  => (float) $flight['flight_price_tax'],
                self::RESPONSE_OUTBOUND => [
                    self::RESPONSE_FLIGHT_CARRIER      => $flight['flight_airline_code'],
                    self::RESPONSE_FLIGHT_NUMBER       => $flight['flight_airline_code'] . $flight['flight_number'],
                    self::RESPONSE_DEPART => [
                        self::RESPONSE_AIRPORT_CODE    => $flight['departure_airport_code'],
                        self::RESPONSE_AIRPORT_NAME    => $flight['departure_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['departure_airport_country'],
                        self::RESPONSE_AIRPORT_CITY    => $flight['departure_airport_city'],
                        self::RESPONSE_DATE_TIME       => $flight['departure_time'],
                    ],
                    self::RESPONSE_ARRIVE => [
                        self::RESPONSE_AIRPORT_CODE    => $flight['arrival_airport_code'],
                        self::RESPONSE_AIRPORT_NAME    => $flight['arrival_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['arrival_airport_country'],
                        self::RESPONSE_AIRPORT_CITY    => $flight['arrival_airport_city'],
                        self::RESPONSE_DATE_TIME       => $flight['arrival_time'],
                    ],
                    self::RESPONSE_CABIN_CODE          => 'Y', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DISTANCE            => $flight['flight_distance'],
                    self::RESPONSE_DURATION            => $flight['flight_duration'],
                    self::RESPONSE_RATING              => (float) $flight['flight_rating'],
                ],
                self::RESPONSE_RETURNING => [],
            ];
        }, $flights);
    }

    /**
     * @return array
     */
    private function getRoundtripFlights(): array
    {
        $columns = [
            'out_flight.id              AS outbound_flight_id',
            'out_flight.airline         AS outbound_airline_code',
            'out_flight.number          AS outbound_flight_number',
            'out_airline.title          AS outbound_airline_title',
            'out_airport.code           AS outbound_departure_airport_code',
            'out_airport.title          AS outbound_departure_airport_title',
            'out_country.title          AS outbound_departure_airport_country',
            'out_airport.city           AS outbound_departure_airport_city',
            'out_flight.departure_time  AS outbound_departure_time',
            'out_airport.timezone_name  AS outbound_departure_timezone',
            'out_flight.arrival_time    AS outbound_arrival_time',
            'out_arrival_airport.code   AS outbound_arrival_airport_code',
            'out_arrival_airport.title  AS outbound_arrival_airport_title',
            'out_arrival_country.title  AS outbound_arrival_airport_country',
            'out_arrival_airport.city   AS outbound_arrival_airport_city',
            'out_flight.distance        AS outbound_distance',
            'out_flight.duration        AS outbound_duration',
            'out_flight.price_base      AS outbound_price_base',
            'out_flight.price_tax       AS outbound_price_tax',
            'out_flight.rating          AS outbound_rating',
            'in_flight.id               AS return_flight_id',
            'in_flight.airline          AS return_airline_code',
            'in_flight.number           AS return_flight_number',
            'in_airline.title           AS return_airline_title',
            'in_airport.code            AS return_departure_airport_code',
            'in_airport.title           AS return_departure_airport_title',
            'in_country.title           AS return_departure_airport_country',
            'in_airport.city            AS return_departure_airport_city',
            'in_flight.departure_time   AS return_departure_time',
            'in_airport.timezone_name   AS return_departure_timezone',
            'in_flight.arrival_time     AS return_arrival_time',
            'in_arrival_airport.code    AS return_arrival_airport_code',
            'in_arrival_airport.title   AS return_arrival_airport_title',
            'in_arrival_country.title   AS return_arrival_airport_country',
            'in_arrival_airport.city    AS return_arrival_airport_city',
            'in_flight.distance         AS return_distance',
            'in_flight.duration         AS return_duration',
            'in_flight.price_base       AS return_price_base',
            'in_flight.price_tax        AS return_price_tax',
            'in_flight.rating           AS return_rating',
        ];

        $this->db->join('airports out_airport',          'out_flight.departure_airport = out_airport.code');
        $this->db->join('airports out_arrival_airport',  'out_flight.arrival_airport = out_arrival_airport.code');
        $this->db->join('airlines out_airline',          'out_flight.airline = out_airline.code');
        $this->db->join('countries out_country',         'out_airport.country_code = out_country.code');

        $this->db->join('flights in_flight',             'out_flight.arrival_airport = in_flight.departure_airport');
        $this->db->joinWhere('flights in_flight',        'DATE(in_flight.departure_time)', $this->returnDate);

        $this->db->join('airports in_airport',           'in_flight.departure_airport = in_airport.code');
        $this->db->join('airports in_arrival_airport',   'in_flight.arrival_airport = in_arrival_airport.code');
        $this->db->join('airlines in_airline',           'in_flight.airline = in_airline.code');
        $this->db->join('countries in_country',          'in_airport.country_code = in_country.code');

        $this->db->join('countries out_arrival_country', 'out_arrival_airport.country_code = out_arrival_country.code');
        $this->db->join('countries in_arrival_country',  'in_arrival_airport.country_code = in_arrival_country.code');

        $this->db->where('(out_airport.code = ? OR out_airport.city_code = ?)', array_fill(0, 2, $this->from));
        $this->db->where('(out_arrival_airport.code = ? OR out_arrival_airport.city_code = ?)', array_fill(0, 2, $this->to));
        $this->db->where('(in_airport.code = ? OR in_airport.city_code = ?)', array_fill(0, 2, $this->to));
        $this->db->where('(in_arrival_airport.code = ? OR in_arrival_airport.city_code = ?)', array_fill(0, 2, $this->from));
        $this->db->where('DATE(out_flight.departure_time) = ?', [$this->departDate]);

        $flights = $this->db->get('flights AS out_flight', null, $columns);


        return array_map(function($flight) {
            $price_base = (float) number_format($flight['outbound_price_base'] + $flight['return_price_base'], 2);
            $price_tax  = (float) number_format($flight['outbound_price_tax'] + $flight['return_price_tax'], 2);

            return [
                self::RESPONSE_PRICE_BASE => $price_base,
                self::RESPONSE_PRICE_TAX  => $price_tax,
                self::RESPONSE_OUTBOUND => [
                    self::RESPONSE_FLIGHT_CARRIER      => $flight['outbound_airline_code'],
                    self::RESPONSE_FLIGHT_NUMBER       => $flight['outbound_airline_code'] . $flight['outbound_flight_number'],
                    self::RESPONSE_DEPART => [
                        self::RESPONSE_AIRPORT_CODE    => $flight['outbound_departure_airport_code'],
                        self::RESPONSE_AIRPORT_NAME    => $flight['outbound_departure_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['outbound_departure_airport_country'],
                        self::RESPONSE_AIRPORT_CITY    => $flight['outbound_departure_airport_city'],
                        self::RESPONSE_DATE_TIME       => $flight['outbound_departure_time'],
                    ],
                    self::RESPONSE_ARRIVE => [
                        self::RESPONSE_AIRPORT_CODE    => $flight['outbound_arrival_airport_code'],
                        self::RESPONSE_AIRPORT_NAME    => $flight['outbound_arrival_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['outbound_arrival_airport_country'],
                        self::RESPONSE_AIRPORT_CITY    => $flight['outbound_arrival_airport_city'],
                        self::RESPONSE_DATE_TIME       => $flight['outbound_arrival_time'],
                    ],
                    self::RESPONSE_CABIN_CODE          => 'Y', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DURATION            => $flight['outbound_duration'],
                    self::RESPONSE_DISTANCE            => $flight['outbound_distance'],
                    self::RESPONSE_RATING              => (float) $flight['outbound_rating'],
                ],
                self::RESPONSE_RETURNING => [
                    self::RESPONSE_FLIGHT_CARRIER      => $flight['return_airline_code'],
                    self::RESPONSE_FLIGHT_NUMBER       => $flight['return_airline_code'] . $flight['return_flight_number'],
                    self::RESPONSE_DEPART => [
                        self::RESPONSE_AIRPORT_CODE    => $flight['return_departure_airport_code'],
                        self::RESPONSE_AIRPORT_NAME    => $flight['return_departure_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['return_departure_airport_country'],
                        self::RESPONSE_AIRPORT_CITY    => $flight['return_departure_airport_city'],
                        self::RESPONSE_DATE_TIME       => $flight['return_departure_time'],
                    ],
                    self::RESPONSE_ARRIVE => [
                        self::RESPONSE_AIRPORT_CODE    => $flight['return_arrival_airport_code'],
                        self::RESPONSE_AIRPORT_NAME    => $flight['return_arrival_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['return_arrival_airport_country'],
                        self::RESPONSE_AIRPORT_CITY    => $flight['return_arrival_airport_city'],
                        self::RESPONSE_DATE_TIME       => $flight['return_arrival_time'],
                    ],
                    self::RESPONSE_CABIN_CODE          => 'X', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DISTANCE            => $flight['return_distance'],
                    self::RESPONSE_DURATION            => $flight['return_duration'],
                    self::RESPONSE_RATING              => (float) $flight['return_rating'],
                ],
            ];
        }, $flights);
    }

    /**
     * @param $from
     * @return $this
     */
    private function setFrom($from): static
    {
        $this->from = $from;

        return $this;
    }

    /**
     * @param $to
     * @return $this
     */
    private function setTo($to): static
    {
        $this->to = $to;

        return $this;
    }

    /**
     * @param $departDate
     * @return $this
     */
    private function setDepartDate($departDate): static
    {
        $this->departDate = $departDate;

        return $this;
    }

    /**
     * @param $returnDate
     * @return $this
     */
    private function setReturnDate($returnDate): static
    {
        $this->returnDate = $returnDate;

        return $this;
    }

    /**
     * @param int $adultNum
     * @return $this
     */
    private function setAdultNum(int $adultNum): static
    {
        $this->adultNum = $adultNum;

        return $this;
    }

    /**
     * @param int $childNum
     * @return $this
     */
    private function setChildNum(int $childNum): static
    {
        $this->childNum = $childNum;

        return $this;
    }

}
