<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Flight search queries.
 *
 * Rows come back with normalised leg aliases: `out_*` for the primary/outbound
 * leg and `in_*` for the return leg, so the caller can map both legs uniformly.
 */
final readonly class FlightRepository
{
    private const int PER_PAGE = 10;

    public function __construct(private Connection $connection) {}

    /**
     * One-way flights for a route/date, paginated.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function onewaySearch(string $from, string $to, string $departDate, SortMethod $sort, int $page): array
    {
        $fromJoins = ' FROM ' . Table::Flights->value . ' flight'
            . ' INNER JOIN ' . Table::Airports->value . ' depart_airport ON flight.departure_airport = depart_airport.code'
            . ' INNER JOIN ' . Table::Airports->value . ' arrive_airport ON flight.arrival_airport = arrive_airport.code'
            . ' INNER JOIN ' . Table::Airlines->value . ' airline ON flight.airline = airline.code'
            . ' INNER JOIN ' . Table::Countries->value . ' depart_country ON depart_airport.country_code = depart_country.code'
            . ' INNER JOIN ' . Table::Countries->value . ' arrive_country ON arrive_airport.country_code = arrive_country.code';

        $where = ' WHERE (depart_airport.code = ? or depart_airport.city_code = ?)'
            . ' AND (arrive_airport.code = ? or arrive_airport.city_code = ?)'
            . ' AND DATE(flight.departure_time) = ?';

        $params = [$from, $from, $to, $to, $departDate];

        $total = (int) $this->connection->fetchValue('SELECT count(1)' . $fromJoins . $where, $params);

        $columns = implode(', ', $this->legColumns('out', [
            'flight' => 'flight',
            'airline' => 'airline',
            'depAirport' => 'depart_airport',
            'depCountry' => 'depart_country',
            'arrAirport' => 'arrive_airport',
            'arrCountry' => 'arrive_country',
        ]));

        $sql = 'SELECT ' . $columns . $fromJoins . $where
            . ' ORDER BY ' . $sort->onewayOrderBy() . ' ASC'
            . ' LIMIT ' . $this->offset($page) . ', ' . self::PER_PAGE;

        return ['rows' => $this->connection->fetchAll($sql, $params), 'total' => $total];
    }

    /**
     * A single one-way flight by id (outbound leg only), or null.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $flightId): ?array
    {
        $columns = implode(', ', $this->legColumns('out', [
            'flight' => 'flight',
            'airline' => 'airline',
            'depAirport' => 'depart_airport',
            'depCountry' => 'depart_country',
            'arrAirport' => 'arrive_airport',
            'arrCountry' => 'arrive_country',
        ]));

        return $this->connection->fetchOne(
            'SELECT ' . $columns
            . ' FROM ' . Table::Flights->value . ' flight'
            . ' INNER JOIN ' . Table::Airports->value . ' depart_airport ON flight.departure_airport = depart_airport.code'
            . ' INNER JOIN ' . Table::Airports->value . ' arrive_airport ON flight.arrival_airport = arrive_airport.code'
            . ' INNER JOIN ' . Table::Airlines->value . ' airline ON flight.airline = airline.code'
            . ' INNER JOIN ' . Table::Countries->value . ' depart_country ON depart_airport.country_code = depart_country.code'
            . ' INNER JOIN ' . Table::Countries->value . ' arrive_country ON arrive_airport.country_code = arrive_country.code'
            . ' WHERE flight.id = ?',
            [$flightId],
        );
    }

    /**
     * Round-trip pairings for a route and out/return dates, paginated.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function roundtripSearch(string $from, string $to, string $departDate, string $returnDate, SortMethod $sort, int $page): array
    {
        // The return-leg date lives in the JOIN condition, so its param comes
        // first in the statement (matching the legacy joinWhere ordering).
        $fromJoins = ' FROM ' . Table::Flights->value . ' out_flight'
            . ' INNER JOIN ' . Table::Airports->value . ' out_airport ON out_flight.departure_airport = out_airport.code'
            . ' INNER JOIN ' . Table::Airports->value . ' out_arrival_airport ON out_flight.arrival_airport = out_arrival_airport.code'
            . ' INNER JOIN ' . Table::Airlines->value . ' out_airline ON out_flight.airline = out_airline.code'
            . ' INNER JOIN ' . Table::Countries->value . ' out_country ON out_airport.country_code = out_country.code'
            . ' INNER JOIN ' . Table::Flights->value . ' in_flight ON out_flight.arrival_airport = in_flight.departure_airport AND DATE(in_flight.departure_time) = ?'
            . ' INNER JOIN ' . Table::Airports->value . ' in_airport ON in_flight.departure_airport = in_airport.code'
            . ' INNER JOIN ' . Table::Airports->value . ' in_arrival_airport ON in_flight.arrival_airport = in_arrival_airport.code'
            . ' INNER JOIN ' . Table::Airlines->value . ' in_airline ON in_flight.airline = in_airline.code'
            . ' INNER JOIN ' . Table::Countries->value . ' in_country ON in_airport.country_code = in_country.code'
            . ' INNER JOIN ' . Table::Countries->value . ' out_arrival_country ON out_arrival_airport.country_code = out_arrival_country.code'
            . ' INNER JOIN ' . Table::Countries->value . ' in_arrival_country ON in_arrival_airport.country_code = in_arrival_country.code';

        $where = ' WHERE (out_airport.code = ? OR out_airport.city_code = ?)'
            . ' AND (out_arrival_airport.code = ? OR out_arrival_airport.city_code = ?)'
            . ' AND (in_airport.code = ? OR in_airport.city_code = ?)'
            . ' AND (in_arrival_airport.code = ? OR in_arrival_airport.city_code = ?)'
            . ' AND DATE(out_flight.departure_time) = ?';

        $params = [$returnDate, $from, $from, $to, $to, $to, $to, $from, $from, $departDate];

        $total = (int) $this->connection->fetchValue('SELECT count(1)' . $fromJoins . $where, $params);

        $columns = implode(', ', array_merge(
            $this->legColumns('out', [
                'flight' => 'out_flight',
                'airline' => 'out_airline',
                'depAirport' => 'out_airport',
                'depCountry' => 'out_country',
                'arrAirport' => 'out_arrival_airport',
                'arrCountry' => 'out_arrival_country',
            ]),
            $this->legColumns('in', [
                'flight' => 'in_flight',
                'airline' => 'in_airline',
                'depAirport' => 'in_airport',
                'depCountry' => 'in_country',
                'arrAirport' => 'in_arrival_airport',
                'arrCountry' => 'in_arrival_country',
            ]),
        ));

        $sql = 'SELECT ' . $columns . $fromJoins . $where
            . ' ORDER BY ' . $sort->roundtripOrderBy() . ' ASC'
            . ' LIMIT ' . $this->offset($page) . ', ' . self::PER_PAGE;

        return ['rows' => $this->connection->fetchAll($sql, $params), 'total' => $total];
    }

    private function offset(int $page): int
    {
        return ($page - 1) * self::PER_PAGE;
    }

    /**
     * Build the SELECT list for one leg, aliased with the given output prefix.
     *
     * @param array{flight: string, airline: string, depAirport: string, depCountry: string, arrAirport: string, arrCountry: string} $t
     * @return list<string>
     */
    private function legColumns(string $pfx, array $t): array
    {
        return [
            "{$t['flight']}.id AS {$pfx}_id",
            "{$t['flight']}.airline AS {$pfx}_carrier",
            "{$t['airline']}.title AS {$pfx}_carrier_name",
            "{$t['flight']}.number AS {$pfx}_number",
            "{$t['depAirport']}.code AS {$pfx}_dep_code",
            "{$t['depAirport']}.title AS {$pfx}_dep_name",
            "{$t['depCountry']}.title AS {$pfx}_dep_country",
            "{$t['depAirport']}.city AS {$pfx}_dep_city",
            "{$t['flight']}.departure_time AS {$pfx}_dep_datetime",
            "{$t['arrAirport']}.code AS {$pfx}_arr_code",
            "{$t['arrAirport']}.title AS {$pfx}_arr_name",
            "{$t['arrCountry']}.title AS {$pfx}_arr_country",
            "{$t['arrAirport']}.city AS {$pfx}_arr_city",
            "{$t['flight']}.arrival_time AS {$pfx}_arr_datetime",
            "{$t['flight']}.distance AS {$pfx}_distance",
            "{$t['flight']}.duration AS {$pfx}_duration",
            "{$t['flight']}.price_base AS {$pfx}_price_base",
            "{$t['flight']}.price_tax AS {$pfx}_price_tax",
            "{$t['flight']}.rating AS {$pfx}_rating",
        ];
    }
}
