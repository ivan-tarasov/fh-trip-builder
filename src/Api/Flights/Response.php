<?php

namespace TripBuilder\Api\Flights;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\HttpException;

class Response extends AbstractApi
{
    private const PER_PAGE_LIMIT = 10;

    private const DATA_PAGE = 'page';
    private const DATA_SORT = 'sort';
    private const DATA_TRIPTYPE = 'trip_type';
    private const DATA_DEPART = 'from';
    private const DATA_ARRIVE = 'to';
    private const DATA_DEPART_DATE = 'depart_date';
    private const DATA_RETURN_DATE = 'return_date';
    private const DATA_ADULT_COUNT = 'adult_count';
    private const DATA_CHILD_COUNT = 'child_count';
    private const DATA_FLIGHT_ID = 'id';

    private const TRIPTYPE_ROUNDTRIP = 'roundtrip';
    private const TRIPTYPE_ONEWAY = 'oneway';

    private const RESPONSE_FLIGHT_ID = 'id';
    private const RESPONSE_CURRENT_PAGE = 'current_page';
    private const RESPONSE_TOTAL_PAGES = 'total_pages';
    private const RESPONSE_PER_PAGE = 'per_page';
    private const RESPONSE_TOTAL_FLIGHTS = 'total_flights';
    private const RESPONSE_FLIGHTS = 'flights';
    private const RESPONSE_OUTBOUND = 'outbound';
    private const RESPONSE_RETURNING = 'returning';
    private const RESPONSE_DEPART = 'depart';
    private const RESPONSE_ARRIVE = 'arrive';
    private const RESPONSE_FLIGHT_NUMBER = 'number';
    private const RESPONSE_AIRPORT_CODE = 'airport_code';
    private const RESPONSE_AIRPORT_NAME = 'airport_name';
    private const RESPONSE_AIRPORT_COUNTRY = 'airport_country';
    private const RESPONSE_AIRPORT_CITY = 'airport_city';
    private const RESPONSE_DATE_TIME = 'date_time';
    private const RESPONSE_FLIGHT_CARRIER_CODE = 'carrier';
    private const RESPONSE_FLIGHT_CARRIER_NAME = 'carrier_name';
    private const RESPONSE_CABIN_CODE = 'cabin_code';
    private const RESPONSE_DISTANCE = 'distance';
    private const RESPONSE_DURATION = 'duration';
    private const RESPONSE_PRICE_BASE = 'price_base';
    private const RESPONSE_PRICE_TAX = 'price_tax';
    private const RESPONSE_RATING = 'rating';

    private const SORT_METHOD_PRICE = 'price';
    private const SORT_METHOD_DURATION = 'duration';
    private const SORT_METHOD_DEPART = 'depart_time';
    private const SORT_METHOD_ARRIVE = 'arrive_time';
    private const SORT_METHOD_RATING = 'rating';

    private const SORT_ONEWAY = [
        self::SORT_METHOD_PRICE => '(flight_price_base + flight_price_tax)',
        self::SORT_METHOD_DURATION => 'flight_duration',
        self::SORT_METHOD_DEPART => 'departure_time',
        self::SORT_METHOD_ARRIVE => 'arrival_time',
        self::SORT_METHOD_RATING => 'flight_rating',
    ];
    private const SORT_ROUNDTRIP = [
        self::SORT_METHOD_PRICE => '(outbound_price_base + outbound_price_tax + return_price_base + return_price_tax)',
        self::SORT_METHOD_DURATION => '(outbound_duration + return_duration)',
        self::SORT_METHOD_RATING => '(outbound_rating + return_rating)',
    ];

    private int $currentPage;
    private string $sort;
    private string $from;
    private string $to;
    private string $departDate;
    private string $returnDate;
    private int $adultNum;
    private int $childNum;
    private int $totalFlights;

    /**
     * @throws Exception
     */
    public function get(): void
    {
        // Throw Bad Request Exception if data or one of the necessary params is empty
        if (empty($this->data)
            || empty($this->data[self::DATA_TRIPTYPE])
            || empty($this->data[self::DATA_DEPART])
            || empty($this->data[self::DATA_ARRIVE])
            || empty($this->data[self::DATA_DEPART_DATE])
            || empty($this->data[self::DATA_ADULT_COUNT])
        ) {
             HttpException::badRequest();
        }

        $this->setCurrentPage(max(1, (int) ($this->data[self::DATA_PAGE] ?? 1)))
            ->setSort($this->data[self::DATA_SORT] ?? self::SORT_METHOD_PRICE)
            ->setFrom($this->data[self::DATA_DEPART])
            ->setTo($this->data[self::DATA_ARRIVE])
            ->setDepartDate($this->data[self::DATA_DEPART_DATE])
            ->setReturnDate($this->data[self::DATA_RETURN_DATE] ?? '')
            ->setAdultNum((int) $this->data[self::DATA_ADULT_COUNT])
            ->setChildNum((int) ($this->data[self::DATA_CHILD_COUNT] ?? 0));

        // Updating search stats
        $this->updateSearchStats(self::DB_TABLE_AIRPORTS, [$this->from, $this->to]);

        // Get the depart the city
        $this->db->where('code', $this->from);
        $this->db->orWhere('city_code', $this->from);
        $airport = $this->db->getValue(self::DB_TABLE_AIRPORTS, 'city');
        $cities[self::RESPONSE_DEPART] = sprintf('%s (%s)', $airport, $this->from);

        // Get the arrival city
        $this->db->where('code', $this->to);
        $this->db->orWhere('city_code', $this->to);
        $airport = $this->db->getValue(self::DB_TABLE_AIRPORTS, 'city');
        $cities[self::RESPONSE_ARRIVE] = sprintf('%s (%s)', $airport, $this->to);

        $flights = match ($this->data[self::DATA_TRIPTYPE]) {
            self::TRIPTYPE_ONEWAY => $this->getOnewayFlights(),
            self::TRIPTYPE_ROUNDTRIP => $this->getRoundtripFlights(),
            default => ['error' => 'Wrong trip type'],
        };

        $this->sendResponse(200, [
            self::RESPONSE_CURRENT_PAGE => $this->currentPage,
            self::RESPONSE_TOTAL_PAGES => (int) ceil($this->totalFlights / self::PER_PAGE_LIMIT),
            self::RESPONSE_PER_PAGE => self::PER_PAGE_LIMIT,
            self::RESPONSE_TOTAL_FLIGHTS => $this->totalFlights,
            self::DATA_TRIPTYPE => $this->data[self::DATA_TRIPTYPE],
            self::RESPONSE_DEPART => $cities[self::RESPONSE_DEPART],
            self::RESPONSE_ARRIVE => $cities[self::RESPONSE_ARRIVE],
            self::DATA_ADULT_COUNT => $this->adultNum,
            self::DATA_CHILD_COUNT => $this->childNum,
            self::RESPONSE_FLIGHTS => $flights,
        ]);
    }

    public function getOne(): void
    {
        // Throw Bad Request Exception if depart_id is empty
        if (empty($this->data) || empty($this->data[self::DATA_FLIGHT_ID])) {
            HttpException::badRequest();
        }

        $response = $this->getOnewayFlights((int) $this->data[self::DATA_FLIGHT_ID]);

        if (empty($response)) {
            HttpException::notFound('Flight not found');
        }

        $response = $response[0];

        $flight = array_merge($response['outbound'], [
            'price_base' => (float) $response['price_base'],
            'price_tax' => (float) $response['price_tax'],
        ]);

        // Updating search stats
        $this->updateSearchStats(self::DB_TABLE_AIRLINES, [$flight['carrier']]);

        $this->sendResponse(200, $flight);
    }

    private function getOnewayFlights(?int $flight_id = null): array
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

        $this->db->join(sprintf('%s depart_airport', self::DB_TABLE_AIRPORTS), 'flight.departure_airport = depart_airport.code');
        $this->db->join(sprintf('%s arrive_airport', self::DB_TABLE_AIRPORTS), 'flight.arrival_airport = arrive_airport.code');
        $this->db->join(sprintf('%s airline', self::DB_TABLE_AIRLINES), 'flight.airline = airline.code');
        $this->db->join(sprintf('%s depart_country', self::DB_TABLE_COUNTRIES), 'depart_airport.country_code = depart_country.code');
        $this->db->join(sprintf('%s arrive_country', self::DB_TABLE_COUNTRIES), 'arrive_airport.country_code = arrive_country.code');

        if (empty($flight_id)) {
            $this->db->where('(depart_airport.code = ? or depart_airport.city_code = ?)', array_fill(0, 2, $this->from));
            $this->db->where('(arrive_airport.code = ? or arrive_airport.city_code = ?)', array_fill(0, 2, $this->to));
            $this->db->where('DATE(flight.departure_time)', $this->departDate);

            $total = $this->db->copy();
            $this->setTotalFlights((int) $total->getValue(self::DB_TABLE_FLIGHTS . ' flight', 'count(1)'));

            $this->db->orderBy(self::SORT_ONEWAY[$this->sort] ?? self::SORT_ONEWAY[self::SORT_METHOD_PRICE], 'asc');

            $flights = $this->db->get(
                self::DB_TABLE_FLIGHTS . ' flight',
                [($this->currentPage - 1) * self::PER_PAGE_LIMIT, self::PER_PAGE_LIMIT],
                $columns,
            );
        } else {
            $this->db->where('flight.id', $flight_id);
            $flights = $this->db->get(self::DB_TABLE_FLIGHTS . ' flight', null, $columns);
        }

        return array_map(function($flight) {
            return [
                self::RESPONSE_PRICE_BASE => $flight['flight_price_base'],
                self::RESPONSE_PRICE_TAX => $flight['flight_price_tax'],
                self::RESPONSE_OUTBOUND => [
                    self::RESPONSE_FLIGHT_ID => $flight['flight_id'],
                    self::RESPONSE_FLIGHT_CARRIER_CODE => $flight['flight_airline_code'],
                    self::RESPONSE_FLIGHT_CARRIER_NAME => $flight['flight_airline_title'],
                    self::RESPONSE_FLIGHT_NUMBER => sprintf(
                        '%s-%d',
                        $flight['flight_airline_code'],
                        $flight['flight_number']
                    ),
                    self::RESPONSE_DEPART => [
                        self::RESPONSE_AIRPORT_CODE => $flight['departure_airport_code'],
                        self::RESPONSE_AIRPORT_NAME => $flight['departure_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['departure_airport_country'],
                        self::RESPONSE_AIRPORT_CITY => $flight['departure_airport_city'],
                        self::RESPONSE_DATE_TIME => $flight['departure_time'],
                    ],
                    self::RESPONSE_ARRIVE => [
                        self::RESPONSE_AIRPORT_CODE => $flight['arrival_airport_code'],
                        self::RESPONSE_AIRPORT_NAME => $flight['arrival_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['arrival_airport_country'],
                        self::RESPONSE_AIRPORT_CITY => $flight['arrival_airport_city'],
                        self::RESPONSE_DATE_TIME => $flight['arrival_time'],
                    ],
                    self::RESPONSE_CABIN_CODE => 'Y', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DISTANCE => $flight['flight_distance'],
                    self::RESPONSE_DURATION => $flight['flight_duration'],
                    self::RESPONSE_RATING => (float) $flight['flight_rating'],
                ],
                self::RESPONSE_RETURNING => [],
            ];
        }, $flights);
    }

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

        $this->db->join(sprintf('%s out_airport', self::DB_TABLE_AIRPORTS), 'out_flight.departure_airport = out_airport.code');
        $this->db->join(sprintf('%s out_arrival_airport', self::DB_TABLE_AIRPORTS), 'out_flight.arrival_airport = out_arrival_airport.code');
        $this->db->join(sprintf('%s out_airline', self::DB_TABLE_AIRLINES), 'out_flight.airline = out_airline.code');
        $this->db->join(sprintf('%s out_country', self::DB_TABLE_COUNTRIES),'out_airport.country_code = out_country.code');

        $this->db->join(sprintf('%s in_flight', self::DB_TABLE_FLIGHTS), 'out_flight.arrival_airport = in_flight.departure_airport');
        $this->db->joinWhere(sprintf('%s in_flight', self::DB_TABLE_FLIGHTS), 'DATE(in_flight.departure_time)', $this->returnDate);

        $this->db->join(sprintf('%s in_airport', self::DB_TABLE_AIRPORTS), 'in_flight.departure_airport = in_airport.code');
        $this->db->join(sprintf('%s in_arrival_airport', self::DB_TABLE_AIRPORTS), 'in_flight.arrival_airport = in_arrival_airport.code');
        $this->db->join(sprintf('%s in_airline', self::DB_TABLE_AIRLINES), 'in_flight.airline = in_airline.code');
        $this->db->join(sprintf('%s in_country', self::DB_TABLE_COUNTRIES), 'in_airport.country_code = in_country.code');

        $this->db->join(sprintf('%s out_arrival_country', self::DB_TABLE_COUNTRIES), 'out_arrival_airport.country_code = out_arrival_country.code');
        $this->db->join(sprintf('%s in_arrival_country', self::DB_TABLE_COUNTRIES), 'in_arrival_airport.country_code = in_arrival_country.code');

        $this->db->where('(out_airport.code = ? OR out_airport.city_code = ?)', array_fill(0, 2, $this->from));
        $this->db->where('(out_arrival_airport.code = ? OR out_arrival_airport.city_code = ?)', array_fill(0, 2, $this->to));
        $this->db->where('(in_airport.code = ? OR in_airport.city_code = ?)', array_fill(0, 2, $this->to));
        $this->db->where('(in_arrival_airport.code = ? OR in_arrival_airport.city_code = ?)', array_fill(0, 2, $this->from));
        $this->db->where('DATE(out_flight.departure_time) = ?', [$this->departDate]);

        $total = $this->db->copy();
        $this->setTotalFlights((int) $total->getValue(self::DB_TABLE_FLIGHTS . ' AS out_flight', 'count(1)'));

        $this->db->orderBy(self::SORT_ROUNDTRIP[$this->sort] ?? self::SORT_ROUNDTRIP[self::SORT_METHOD_PRICE], 'asc');

        $flights = $this->db->get(
            self::DB_TABLE_FLIGHTS . ' AS out_flight',
            [($this->currentPage - 1) * self::PER_PAGE_LIMIT, self::PER_PAGE_LIMIT],
            $columns,
        );

        return array_map(function($flight) {
            $price_base = $flight['outbound_price_base'] + $flight['return_price_base'];
            $price_tax = round($flight['outbound_price_tax'] + $flight['return_price_tax'], 2);

            return [
                self::RESPONSE_PRICE_BASE => $price_base,
                self::RESPONSE_PRICE_TAX => $price_tax,
                self::RESPONSE_OUTBOUND => [
                    self::RESPONSE_FLIGHT_ID => $flight['outbound_flight_id'],
                    self::RESPONSE_FLIGHT_CARRIER_CODE => $flight['outbound_airline_code'],
                    self::RESPONSE_FLIGHT_CARRIER_NAME => $flight['outbound_airline_title'],
                    self::RESPONSE_FLIGHT_NUMBER => sprintf(
                        '%s-%d',
                        $flight['outbound_airline_code'],
                        $flight['outbound_flight_number']
                    ),
                    self::RESPONSE_DEPART => [
                        self::RESPONSE_AIRPORT_CODE => $flight['outbound_departure_airport_code'],
                        self::RESPONSE_AIRPORT_NAME => $flight['outbound_departure_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['outbound_departure_airport_country'],
                        self::RESPONSE_AIRPORT_CITY => $flight['outbound_departure_airport_city'],
                        self::RESPONSE_DATE_TIME => $flight['outbound_departure_time'],
                    ],
                    self::RESPONSE_ARRIVE => [
                        self::RESPONSE_AIRPORT_CODE => $flight['outbound_arrival_airport_code'],
                        self::RESPONSE_AIRPORT_NAME => $flight['outbound_arrival_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['outbound_arrival_airport_country'],
                        self::RESPONSE_AIRPORT_CITY => $flight['outbound_arrival_airport_city'],
                        self::RESPONSE_DATE_TIME => $flight['outbound_arrival_time'],
                    ],
                    self::RESPONSE_CABIN_CODE => 'Y', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DURATION => $flight['outbound_duration'],
                    self::RESPONSE_DISTANCE => $flight['outbound_distance'],
                    self::RESPONSE_RATING => (float) $flight['outbound_rating'],
                ],
                self::RESPONSE_RETURNING => [
                    self::RESPONSE_FLIGHT_ID => $flight['return_flight_id'],
                    self::RESPONSE_FLIGHT_CARRIER_CODE => $flight['return_airline_code'],
                    self::RESPONSE_FLIGHT_CARRIER_NAME => $flight['return_airline_title'],
                    self::RESPONSE_FLIGHT_NUMBER => sprintf(
                        '%s-%d',
                        $flight['return_airline_code'],
                        $flight['return_flight_number']
                    ),
                    self::RESPONSE_DEPART => [
                        self::RESPONSE_AIRPORT_CODE => $flight['return_departure_airport_code'],
                        self::RESPONSE_AIRPORT_NAME => $flight['return_departure_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['return_departure_airport_country'],
                        self::RESPONSE_AIRPORT_CITY => $flight['return_departure_airport_city'],
                        self::RESPONSE_DATE_TIME => $flight['return_departure_time'],
                    ],
                    self::RESPONSE_ARRIVE => [
                        self::RESPONSE_AIRPORT_CODE => $flight['return_arrival_airport_code'],
                        self::RESPONSE_AIRPORT_NAME => $flight['return_arrival_airport_title'],
                        self::RESPONSE_AIRPORT_COUNTRY => $flight['return_arrival_airport_country'],
                        self::RESPONSE_AIRPORT_CITY => $flight['return_arrival_airport_city'],
                        self::RESPONSE_DATE_TIME => $flight['return_arrival_time'],
                    ],
                    self::RESPONSE_CABIN_CODE => 'X', // FIXME: we need to add the real one in DB
                    self::RESPONSE_DISTANCE => $flight['return_distance'],
                    self::RESPONSE_DURATION => $flight['return_duration'],
                    self::RESPONSE_RATING => (float) $flight['return_rating'],
                ],
            ];
        }, $flights);
    }

    private function setCurrentPage(int $page): static
    {
        $this->currentPage = $page;
        return $this;
    }

    private function setSort(string $method): static
    {
        $this->sort = $method;
        return $this;
    }

    private function setFrom(string $from): static
    {
        $this->from = $from;
        return $this;
    }

    private function setTo(string $to): static
    {
        $this->to = $to;
        return $this;
    }

    private function setDepartDate(string $departDate): static
    {
        $this->departDate = $departDate;
        return $this;
    }

    private function setReturnDate(string $returnDate): static
    {
        $this->returnDate = $returnDate;
        return $this;
    }

    private function setAdultNum(int $adultNum): static
    {
        $this->adultNum = $adultNum;
        return $this;
    }

    private function setChildNum(int $childNum): static
    {
        $this->childNum = $childNum;
        return $this;
    }

    private function setTotalFlights(int $count): void
    {
        $this->totalFlights = $count;
    }
}
