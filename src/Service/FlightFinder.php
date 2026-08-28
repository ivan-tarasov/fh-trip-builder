<?php

declare(strict_types=1);

namespace TripBuilder\Service;

use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\Database\Connection;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\TripType;

/**
 * Flight search + shaping, independent of any transport.
 *
 * The HTTP endpoint (Api\Flights\Response) and the page controllers both call
 * this directly, so a page render no longer makes an HTTP request to the app's
 * own API.
 */
final readonly class FlightFinder
{
    private const int PER_PAGE_LIMIT = 10;

    private const string RESPONSE_FLIGHT_ID = 'id';
    private const string RESPONSE_FLIGHTS = 'flights';
    private const string RESPONSE_OUTBOUND = 'outbound';
    private const string RESPONSE_RETURNING = 'returning';
    private const string RESPONSE_DEPART = 'depart';
    private const string RESPONSE_ARRIVE = 'arrive';
    private const string RESPONSE_FLIGHT_NUMBER = 'number';
    private const string RESPONSE_AIRPORT_CODE = 'airport_code';
    private const string RESPONSE_AIRPORT_NAME = 'airport_name';
    private const string RESPONSE_AIRPORT_COUNTRY = 'airport_country';
    private const string RESPONSE_AIRPORT_CITY = 'airport_city';
    private const string RESPONSE_DATE_TIME = 'date_time';
    private const string RESPONSE_FLIGHT_CARRIER_CODE = 'carrier';
    private const string RESPONSE_FLIGHT_CARRIER_NAME = 'carrier_name';
    private const string RESPONSE_CABIN_CODE = 'cabin_code';
    private const string RESPONSE_DISTANCE = 'distance';
    private const string RESPONSE_DURATION = 'duration';
    private const string RESPONSE_PRICE_BASE = 'price_base';
    private const string RESPONSE_PRICE_TAX = 'price_tax';
    private const string RESPONSE_RATING = 'rating';

    private const string CABIN_OUTBOUND = 'Y'; // FIXME: we need to add the real one in DB
    private const string CABIN_RETURN = 'X'; // FIXME: we need to add the real one in DB

    public function __construct(private Connection $connection) {}

    /**
     * Run a flight search and shape the paginated result payload.
     *
     * @return array<string, mixed>
     */
    public function search(FlightSearchQuery $query, TripType $tripType): array
    {
        // Updating search stats
        new AirportRepository($this->connection)->recordSearch($query->from, $query->to);

        $airports = new AirportRepository($this->connection);
        $cities = [
            self::RESPONSE_DEPART => sprintf('%s (%s)', $airports->cityByCode($query->from), $query->from),
            self::RESPONSE_ARRIVE => sprintf('%s (%s)', $airports->cityByCode($query->to), $query->to),
        ];

        [$flights, $totalFlights] = match ($tripType) {
            TripType::Oneway => $this->onewayFlights($query),
            TripType::Roundtrip => $this->roundtripFlights($query),
        };

        return [
            'current_page' => $query->currentPage,
            'total_pages' => (int) ceil($totalFlights / self::PER_PAGE_LIMIT),
            'per_page' => self::PER_PAGE_LIMIT,
            'total_flights' => $totalFlights,
            'trip_type' => $tripType->value,
            self::RESPONSE_DEPART => $cities[self::RESPONSE_DEPART],
            self::RESPONSE_ARRIVE => $cities[self::RESPONSE_ARRIVE],
            'adult_count' => $query->adultNum,
            'child_count' => $query->childNum,
            self::RESPONSE_FLIGHTS => $flights,
        ];
    }

    /**
     * A single flight leg by id (for the add-trip flow), or null.
     *
     * @return array<string, mixed>|null
     */
    public function findOne(int $id): ?array
    {
        $flight = new FlightRepository($this->connection)->findById($id);

        if ($flight === null) {
            return null;
        }

        $response = array_merge($this->mapLeg($flight, 'out', self::CABIN_OUTBOUND), [
            self::RESPONSE_PRICE_BASE => (float) $flight['out_price_base'],
            self::RESPONSE_PRICE_TAX => (float) $flight['out_price_tax'],
        ]);

        // Updating booking stats
        new AirlineRepository($this->connection)->recordBooking($response[self::RESPONSE_FLIGHT_CARRIER_CODE]);

        return $response;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function onewayFlights(FlightSearchQuery $query): array
    {
        $result = new FlightRepository($this->connection)->onewaySearch(
            $query->from,
            $query->to,
            $query->departDate,
            SortMethod::fromRequest($query->sort),
            $query->currentPage,
        );

        $rows = array_map(fn(array $row): array => [
            self::RESPONSE_PRICE_BASE => $row['out_price_base'],
            self::RESPONSE_PRICE_TAX => $row['out_price_tax'],
            self::RESPONSE_OUTBOUND => $this->mapLeg($row, 'out', self::CABIN_OUTBOUND),
            self::RESPONSE_RETURNING => [],
        ], $result['rows']);

        return [$rows, $result['total']];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function roundtripFlights(FlightSearchQuery $query): array
    {
        $result = new FlightRepository($this->connection)->roundtripSearch(
            $query->from,
            $query->to,
            $query->departDate,
            $query->returnDate,
            SortMethod::fromRequest($query->sort),
            $query->currentPage,
        );

        $rows = array_map(fn(array $row): array => [
            self::RESPONSE_PRICE_BASE => $row['out_price_base'] + $row['in_price_base'],
            self::RESPONSE_PRICE_TAX => round($row['out_price_tax'] + $row['in_price_tax'], 2),
            self::RESPONSE_OUTBOUND => $this->mapLeg($row, 'out', self::CABIN_OUTBOUND),
            self::RESPONSE_RETURNING => $this->mapLeg($row, 'in', self::CABIN_RETURN),
        ], $result['rows']);

        return [$rows, $result['total']];
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
}
