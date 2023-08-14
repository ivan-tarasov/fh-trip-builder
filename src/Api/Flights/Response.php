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

    const RESPONSE_OUTBOUND            = 'outbound',
          RESPONSE_RETURNING           = 'returning',
          RESPONSE_FLIGHT_NUMBER       = 'flight_number',
          RESPONSE_DEPART_AIRPORT_CODE = 'depart_airport_code',
          RESPONSE_DEPART_AIRPORT_NAME = 'depart_airport_name',
          RESPONSE_DEPART_AIRPORT_CITY = 'depart_airport_city',
          RESPONSE_DEPART_DATE_TIME    = 'depart_time',
          RESPONSE_ARRIVE_AIRPORT_CODE = 'arrive_airport_code',
          RESPONSE_ARRIVE_AIRPORT_NAME = 'arrive_airport_name',
          RESPONSE_ARRIVE_AIRPORT_CITY = 'arrive_airport_city',
          RESPONSE_ARRIVE_DATE_TIME    = 'arrive_time',
          RESPONSE_FLIGHT_CARRIER      = 'carrier',
          RESPONSE_CABIN_CODE          = 'cabin_code',
          RESPONSE_DURATION            = 'duration',
          RESPONSE_PRICE               = 'price',
          RESPONSE_RATING              = 'rating';

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
        $data = file_get_contents('php://input');
        $data = json_decode($data, true);

        // Throw Bad Request Exception if data or one of necessary params is empty
        if (empty($data)
            || empty($data[self::DATA_TRIPTYPE])
            || empty($data[self::DATA_DEPART])
            || empty($data[self::DATA_ARRIVE])
            || empty($data[self::DATA_DEPART_DATE])
            || empty($data[self::DATA_ADULT_COUNT])
        ) {
             HttpException::badRequest();
        }

        $this->setFrom($data[self::DATA_DEPART])
            ->setTo($data[self::DATA_ARRIVE])
            ->setDepartDate($data[self::DATA_DEPART_DATE])
            ->setReturnDate($data[self::DATA_RETURN_DATE] ?: '')
            ->setAdultNum($data[self::DATA_ADULT_COUNT])
            ->setChildNum($data[self::DATA_CHILD_COUNT]);

        $flights = match ($data[self::DATA_TRIPTYPE]) {
            self::TRIPTYPE_ONEWAY    => $this->getOnewayFlights(),
            self::TRIPTYPE_ROUNDTRIP => $this->getRoundtripFlights(),
            default => [],
        };

        $this->sendResponse(200, [
            'triptype' => $data[self::DATA_TRIPTYPE],
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
            'f.id              AS flight_id',
            'f.airline         AS flight_airline_code',
            'al.title          AS flight_airline_title',
            'f.number          AS flight_number',
            'da.code           AS departure_airport_code',
            'da.title          AS departure_airport_title',
            'da.city           AS departure_airport_city',
            'f.departure_time  AS departure_time',
            'da.timezone       AS departure_airport_timezone',
            'aa.code           AS arrival_airport_code',
            'aa.title          AS arrival_airport_title',
            'aa.city           AS arrival_airport_city',
            'f.arrival_time    AS arrival_time',
            'aa.timezone       AS arrival_airport_timezone',
            'f.duration        AS flight_duration',
            'f.price           AS flight_price',
            'f.rating          AS flight_rating',
        ];

        $this->db->join('airports da', 'f.departure_airport = da.code');
        $this->db->join('airports aa', 'f.arrival_airport = aa.code');
        $this->db->join('airlines al', 'f.airline = al.code');

        $this->db->where ('(da.code = ? or da.city_code = ?)', array_fill(0, 2, $this->getFrom()));
        $this->db->where ('(aa.code = ? or aa.city_code = ?)', array_fill(0, 2, $this->getTo()));
        $this->db->where ('DATE(f.departure_time) = ?', [$this->getDepartDate()]);

        $flights = $this->db->get('flights f', null, $columns) ?: [];

        return array_map(function($flight) {
            return [
                self::RESPONSE_PRICE => $flight['flight_price'],
                self::RESPONSE_OUTBOUND => [
                    self::RESPONSE_FLIGHT_NUMBER       => $flight['flight_airline_code'] . $flight['flight_number'],
                    self::RESPONSE_DEPART_AIRPORT_CODE => $flight['departure_airport_code'],
                    self::RESPONSE_DEPART_AIRPORT_NAME => $flight['departure_airport_title'],
                    self::RESPONSE_DEPART_AIRPORT_CITY => $flight['departure_airport_city'],
                    self::RESPONSE_DEPART_DATE_TIME    => $flight['departure_time'],
                    self::RESPONSE_ARRIVE_AIRPORT_CODE => $flight['arrival_airport_code'],
                    self::RESPONSE_ARRIVE_AIRPORT_NAME => $flight['arrival_airport_title'],
                    self::RESPONSE_ARRIVE_AIRPORT_CITY => $flight['arrival_airport_city'],
                    self::RESPONSE_ARRIVE_DATE_TIME    => $flight['arrival_time'],
                    self::RESPONSE_FLIGHT_CARRIER      => $flight['flight_airline_code'],
                    self::RESPONSE_CABIN_CODE          => 'Y', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DURATION            => $flight['flight_duration'],
                    self::RESPONSE_RATING              => $flight['flight_rating'],
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
            'out_airline.title          AS outbound_airline_title',
            'out_flight.number          AS outbound_flight_number',
            'out_airport.code           AS outbound_departure_airport_code',
            'out_airport.title          AS outbound_departure_airport_title',
            'out_airport.city           AS outbound_departure_airport_city',
            'out_flight.departure_time  AS outbound_departure_time',
            'out_airport.timezone       AS outbound_departure_timezone',
            'out_flight.arrival_time    AS outbound_arrival_time',
            'out_arrival_airport.code   AS outbound_arrival_airport_code',
            'out_arrival_airport.title  AS outbound_arrival_airport_title',
            'out_arrival_airport.city   AS outbound_arrival_airport_city',
            'out_flight.duration        AS outbound_duration',
            'out_flight.price           AS outbound_price',
            'out_flight.rating          AS outbound_rating',
            'in_flight.id               AS return_flight_id',
            'in_flight.airline          AS return_airline_code',
            'in_airline.title           AS return_airline_title',
            'in_flight.number           AS return_flight_number',
            'in_airport.code            AS return_departure_airport_code',
            'in_airport.title           AS return_departure_airport_title',
            'in_airport.city            AS return_departure_airport_city',
            'in_flight.departure_time   AS return_departure_time',
            'in_airport.timezone        AS return_departure_timezone',
            'in_flight.arrival_time     AS return_arrival_time',
            'in_arrival_airport.code    AS return_arrival_airport_code',
            'in_arrival_airport.title   AS return_arrival_airport_title',
            'in_arrival_airport.city    AS return_arrival_airport_city',
            'in_flight.duration         AS return_duration',
            'in_flight.price            AS return_price',
            'in_flight.rating           AS return_rating',
        ];

        $this->db->join('airports out_airport', 'out_flight.departure_airport = out_airport.code');
        $this->db->join('airports out_arrival_airport', 'out_flight.arrival_airport = out_arrival_airport.code');
        $this->db->join('airlines out_airline', 'out_flight.airline = out_airline.code');

        $this->db->join('flights in_flight', 'out_flight.arrival_airport = in_flight.departure_airport');
        $this->db->joinWhere('flights in_flight', 'DATE(in_flight.departure_time)', $this->getReturnDate());

        $this->db->join('airports in_airport', 'in_flight.departure_airport = in_airport.code');
        $this->db->join('airports in_arrival_airport', 'in_flight.arrival_airport = in_arrival_airport.code');
        $this->db->join('airlines in_airline', 'in_flight.airline = in_airline.code');

        $this->db->where('(out_airport.code = ? OR out_airport.city_code = ?)', array_fill(0, 2, $this->getFrom()));
        $this->db->where('(out_arrival_airport.code = ? OR out_arrival_airport.city_code = ?)', array_fill(0, 2, $this->getTo()));
        $this->db->where('(in_airport.code = ? OR in_airport.city_code = ?)', array_fill(0, 2, $this->getTo()));
        $this->db->where('(in_arrival_airport.code = ? OR in_arrival_airport.city_code = ?)', array_fill(0, 2, $this->getFrom()));
        $this->db->where('DATE(out_flight.departure_time) = ?', [$this->getDepartDate()]);

        $flights = $this->db->get('flights AS out_flight', null, $columns);

        return array_map(function($flight) {
            return [
                self::RESPONSE_PRICE => $flight['outbound_price'] + $flight['return_price'],
                self::RESPONSE_OUTBOUND => [
                    self::RESPONSE_FLIGHT_NUMBER       => $flight['outbound_airline_code'] . $flight['outbound_flight_number'],
                    self::RESPONSE_DEPART_AIRPORT_CODE => $flight['outbound_departure_airport_code'],
                    self::RESPONSE_DEPART_AIRPORT_NAME => $flight['outbound_departure_airport_title'],
                    self::RESPONSE_DEPART_AIRPORT_CITY => $flight['outbound_departure_airport_city'],
                    self::RESPONSE_DEPART_DATE_TIME    => $flight['outbound_departure_time'],
                    self::RESPONSE_ARRIVE_AIRPORT_CODE => $flight['outbound_arrival_airport_code'],
                    self::RESPONSE_ARRIVE_AIRPORT_NAME => $flight['outbound_arrival_airport_title'],
                    self::RESPONSE_ARRIVE_AIRPORT_CITY => $flight['outbound_arrival_airport_city'],
                    self::RESPONSE_ARRIVE_DATE_TIME    => $flight['outbound_arrival_time'],
                    self::RESPONSE_FLIGHT_CARRIER      => $flight['outbound_airline_code'],
                    self::RESPONSE_CABIN_CODE          => 'Y', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DURATION            => $flight['outbound_duration'],
                    self::RESPONSE_RATING              => $flight['outbound_rating'],
                ],
                self::RESPONSE_RETURNING => [
                    self::RESPONSE_FLIGHT_NUMBER       => $flight['return_airline_code'] . $flight['return_flight_number'],
                    self::RESPONSE_DEPART_AIRPORT_CODE => $flight['return_departure_airport_code'],
                    self::RESPONSE_DEPART_AIRPORT_NAME => $flight['return_departure_airport_title'],
                    self::RESPONSE_DEPART_AIRPORT_CITY => $flight['return_departure_airport_city'],
                    self::RESPONSE_DEPART_DATE_TIME    => $flight['return_departure_time'],
                    self::RESPONSE_ARRIVE_AIRPORT_CODE => $flight['return_arrival_airport_code'],
                    self::RESPONSE_ARRIVE_AIRPORT_NAME => $flight['return_arrival_airport_title'],
                    self::RESPONSE_ARRIVE_AIRPORT_CITY => $flight['return_arrival_airport_city'],
                    self::RESPONSE_ARRIVE_DATE_TIME    => $flight['return_arrival_time'],
                    self::RESPONSE_FLIGHT_CARRIER      => $flight['return_airline_code'],
                    self::RESPONSE_CABIN_CODE          => 'X', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DURATION            => $flight['return_duration'],
                    self::RESPONSE_RATING              => $flight['return_rating'],
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
     * @return string
     */
    private function getFrom(): string
    {
        return $this->from;
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
     * @return string
     */
    private function getTo(): string
    {
        return $this->to;
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
     * @return string
     */
    private function getDepartDate(): string
    {
        return $this->departDate;
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
     * @return string
     */
    private function getReturnDate(): string
    {
        return $this->returnDate;
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
