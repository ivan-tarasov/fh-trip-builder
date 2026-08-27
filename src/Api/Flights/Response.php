<?php

declare(strict_types=1);

namespace TripBuilder\Api\Flights;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\ApiResponder;
use TripBuilder\Api\HttpStatus;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\FlightRepository;

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

    private const CABIN_OUTBOUND = 'Y'; // FIXME: we need to add the real one in DB
    private const CABIN_RETURN = 'X'; // FIXME: we need to add the real one in DB

    private FlightSearchQuery $query;
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
            ApiResponder::badRequest();
        }

        $this->query = new FlightSearchQuery(
            currentPage: max(1, (int) ($this->data[self::DATA_PAGE] ?? 1)),
            sort: $this->data[self::DATA_SORT] ?? SortMethod::Price->value,
            from: $this->data[self::DATA_DEPART],
            to: $this->data[self::DATA_ARRIVE],
            departDate: $this->data[self::DATA_DEPART_DATE],
            returnDate: $this->data[self::DATA_RETURN_DATE] ?? '',
            adultNum: (int) $this->data[self::DATA_ADULT_COUNT],
            childNum: (int) ($this->data[self::DATA_CHILD_COUNT] ?? 0),
        );

        // Updating search stats
        $this->updateSearchStats(self::DB_TABLE_AIRPORTS, [$this->query->from, $this->query->to]);

        $airports = new AirportRepository($this->connection());
        $cities = [
            self::RESPONSE_DEPART => sprintf('%s (%s)', $airports->cityByCode($this->query->from), $this->query->from),
            self::RESPONSE_ARRIVE => sprintf('%s (%s)', $airports->cityByCode($this->query->to), $this->query->to),
        ];

        $flights = match ($this->data[self::DATA_TRIPTYPE]) {
            self::TRIPTYPE_ONEWAY => $this->getOnewayFlights(),
            self::TRIPTYPE_ROUNDTRIP => $this->getRoundtripFlights(),
            default => ['error' => 'Wrong trip type'],
        };

        $this->sendResponse(HttpStatus::Ok, [
            self::RESPONSE_CURRENT_PAGE => $this->query->currentPage,
            self::RESPONSE_TOTAL_PAGES => (int) ceil($this->totalFlights / self::PER_PAGE_LIMIT),
            self::RESPONSE_PER_PAGE => self::PER_PAGE_LIMIT,
            self::RESPONSE_TOTAL_FLIGHTS => $this->totalFlights,
            self::DATA_TRIPTYPE => $this->data[self::DATA_TRIPTYPE],
            self::RESPONSE_DEPART => $cities[self::RESPONSE_DEPART],
            self::RESPONSE_ARRIVE => $cities[self::RESPONSE_ARRIVE],
            self::DATA_ADULT_COUNT => $this->query->adultNum,
            self::DATA_CHILD_COUNT => $this->query->childNum,
            self::RESPONSE_FLIGHTS => $flights,
        ]);
    }

    public function getOne(): void
    {
        // Throw Bad Request Exception if depart_id is empty
        if (empty($this->data) || empty($this->data[self::DATA_FLIGHT_ID])) {
            ApiResponder::badRequest();
        }

        $flight = (new FlightRepository($this->connection()))->findById((int) $this->data[self::DATA_FLIGHT_ID]);

        if ($flight === null) {
            ApiResponder::notFound('Flight not found');
        }

        $response = array_merge($this->mapLeg($flight, 'out', self::CABIN_OUTBOUND), [
            self::RESPONSE_PRICE_BASE => (float) $flight['out_price_base'],
            self::RESPONSE_PRICE_TAX => (float) $flight['out_price_tax'],
        ]);

        // Updating search stats
        $this->updateSearchStats(self::DB_TABLE_AIRLINES, [$response[self::RESPONSE_FLIGHT_CARRIER_CODE]]);

        $this->sendResponse(HttpStatus::Ok, $response);
    }

    private function getOnewayFlights(): array
    {
        $result = (new FlightRepository($this->connection()))->onewaySearch(
            $this->query->from,
            $this->query->to,
            $this->query->departDate,
            SortMethod::fromRequest($this->query->sort),
            $this->query->currentPage,
        );

        $this->setTotalFlights($result['total']);

        return array_map(fn(array $row): array => [
            self::RESPONSE_PRICE_BASE => $row['out_price_base'],
            self::RESPONSE_PRICE_TAX => $row['out_price_tax'],
            self::RESPONSE_OUTBOUND => $this->mapLeg($row, 'out', self::CABIN_OUTBOUND),
            self::RESPONSE_RETURNING => [],
        ], $result['rows']);
    }

    private function getRoundtripFlights(): array
    {
        $result = (new FlightRepository($this->connection()))->roundtripSearch(
            $this->query->from,
            $this->query->to,
            $this->query->departDate,
            $this->query->returnDate,
            SortMethod::fromRequest($this->query->sort),
            $this->query->currentPage,
        );

        $this->setTotalFlights($result['total']);

        return array_map(fn(array $row): array => [
            self::RESPONSE_PRICE_BASE => $row['out_price_base'] + $row['in_price_base'],
            self::RESPONSE_PRICE_TAX => round($row['out_price_tax'] + $row['in_price_tax'], 2),
            self::RESPONSE_OUTBOUND => $this->mapLeg($row, 'out', self::CABIN_OUTBOUND),
            self::RESPONSE_RETURNING => $this->mapLeg($row, 'in', self::CABIN_RETURN),
        ], $result['rows']);
    }

    /**
     * Map one leg of a flight row (aliased `{$prefix}_*`) into the response shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapLeg(array $row, string $prefix, string $cabin): array
    {
        return [
            self::RESPONSE_FLIGHT_ID => $row["{$prefix}_id"],
            self::RESPONSE_FLIGHT_CARRIER_CODE => $row["{$prefix}_carrier"],
            self::RESPONSE_FLIGHT_CARRIER_NAME => $row["{$prefix}_carrier_name"],
            self::RESPONSE_FLIGHT_NUMBER => sprintf('%s-%d', $row["{$prefix}_carrier"], $row["{$prefix}_number"]),
            self::RESPONSE_DEPART => [
                self::RESPONSE_AIRPORT_CODE => $row["{$prefix}_dep_code"],
                self::RESPONSE_AIRPORT_NAME => $row["{$prefix}_dep_name"],
                self::RESPONSE_AIRPORT_COUNTRY => $row["{$prefix}_dep_country"],
                self::RESPONSE_AIRPORT_CITY => $row["{$prefix}_dep_city"],
                self::RESPONSE_DATE_TIME => $row["{$prefix}_dep_datetime"],
            ],
            self::RESPONSE_ARRIVE => [
                self::RESPONSE_AIRPORT_CODE => $row["{$prefix}_arr_code"],
                self::RESPONSE_AIRPORT_NAME => $row["{$prefix}_arr_name"],
                self::RESPONSE_AIRPORT_COUNTRY => $row["{$prefix}_arr_country"],
                self::RESPONSE_AIRPORT_CITY => $row["{$prefix}_arr_city"],
                self::RESPONSE_DATE_TIME => $row["{$prefix}_arr_datetime"],
            ],
            self::RESPONSE_CABIN_CODE => $cabin,
            self::RESPONSE_DISTANCE => $row["{$prefix}_distance"],
            self::RESPONSE_DURATION => $row["{$prefix}_duration"],
            self::RESPONSE_RATING => (float) $row["{$prefix}_rating"],
        ];
    }

    private function setTotalFlights(int $count): void
    {
        $this->totalFlights = $count;
    }
}
