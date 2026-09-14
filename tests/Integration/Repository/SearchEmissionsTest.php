<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The carbon figure where a visitor meets it: on the row, in the sort, in the
 * sidebar.
 *
 * Four aircraft on one route on one day, chosen so the answers cannot be a
 * coincidence: a 787-9 and an A320 below the middle, a 777-300ER and a 747-400
 * above it, and -- in the last test -- a type nobody has a burn figure for,
 * which has to come back as nothing rather than as a very clean flight
 * (C5, #154).
 */
final class SearchEmissionsTest extends IntegrationTestCase
{
    /**
     * Past the horizon, so no generated flight can share this day.
     *
     * Four aircraft on one route on one day, and the day has to be the test's
     * alone: the search counts what the route offered, so one generated flight
     * on the same date would change what "the middle" is.
     *
     * Memoised, so a run that crosses midnight cannot insert on one date and
     * search on the next.
     */
    private static ?string $departDate = null;

    private static function departDate(): string
    {
        return self::$departDate ??= self::dateBeyondGeneratedFlights(14);
    }

    // Enough for every type here to be in range, and long enough that the
    // climb allowance is not what decides the ordering.
    private const int DISTANCE_KM = 5500;

    /** @var list<int> */
    private array $flights = [];

    protected function setUp(): void
    {
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->flights[] = $this->insertFlight('AC', '789', '06:00:00', '13:30:00');
        $this->flights[] = $this->insertFlight('AC', '320', '08:00:00', '15:30:00');
        $this->flights[] = $this->insertFlight('BA', '77W', '10:00:00', '17:30:00');
        $this->flights[] = $this->insertFlight('BA', '744', '12:00:00', '19:30:00');
    }

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ($this->flights as $id) {
            $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$id]);
        }
    }

    public function testEveryRowCarriesItsOwnFigure(): void
    {
        foreach ($this->search()['rows'] as $row) {
            self::assertNotNull($row['co2_kg'], 'A seeded aircraft type produced no estimate.');
            self::assertGreaterThan(0, $row['co2_kg']);
        }
    }

    /**
     * A 787 on the same route as a 747 is the comparison the number is for.
     */
    public function testTheCleanerAircraftReadsCleaner(): void
    {
        $byType = $this->byAircraft();

        foreach (['789', '77W', '744'] as $type) {
            self::assertArrayHasKey($type, $byType);
        }

        self::assertLessThan($byType['77W'], $byType['789']);
        self::assertLessThan($byType['744'], $byType['77W']);
    }

    public function testTheEmissionsSortPutsTheCleanestFirst(): void
    {
        $figures = array_map(
            static fn(array $row): float => (float) $row['co2_kg'],
            $this->search(sort: SortMethod::Emissions)['rows'],
        );

        $sorted = $figures;
        sort($sorted);

        self::assertSame($sorted, $figures);
    }

    /**
     * "Lower than typical" is the middle of what the route offered, so with
     * four itineraries it keeps two.
     */
    public function testTheFilterKeepsTheOnesAtOrBelowTheMiddle(): void
    {
        $all = $this->search();
        $lower = $this->search(filters: new FlightFilters(lowerCo2: true));

        self::assertSame(4, $all['total']);
        self::assertSame(2, $lower['total']);

        foreach ($lower['rows'] as $row) {
            self::assertTrue((bool) $row['co2_typical'], 'The filter kept a row it had not flagged.');
        }
    }

    /**
     * The flag is on the rows the filter keeps and off the ones it drops, so
     * the card and the sidebar cannot disagree.
     *
     * And the answer is the one the number is worth showing for: on a 5,500km
     * leg the 777-300ER reads cleaner than the A320, because 370 seats share
     * the fuel where 162 do. A big aeroplane is not a dirty one, and nobody
     * guesses that from the outside.
     */
    public function testTheRowsAgreeWithTheFilter(): void
    {
        $typical = [];

        foreach ($this->search()['rows'] as $row) {
            $typical[(string) $row['legs'][0]['aircraft_code']] = (bool) $row['co2_typical'];
        }

        foreach (['789', '77W', '320', '744'] as $type) {
            self::assertArrayHasKey($type, $typical);
        }

        self::assertTrue($typical['789']);
        self::assertTrue($typical['77W']);
        self::assertFalse($typical['320']);
        self::assertFalse($typical['744']);
    }

    /**
     * A type with no published burn says nothing, and nothing is not "lower
     * than typical" -- a flight we cannot measure must not be sold as clean.
     */
    public function testAnUnknownTypeSaysNothingAndIsNotTypical(): void
    {
        $this->flights[] = $this->insertFlight('AC', 'ZZZ', '14:00:00', '21:30:00');

        $unknown = null;

        foreach ($this->search()['rows'] as $row) {
            if ($row['legs'][0]['aircraft_code'] === 'ZZZ') {
                $unknown = $row;
            }
        }

        self::assertNotNull($unknown, 'The flight on the unknown type was not returned at all.');
        self::assertNull($unknown['co2_kg']);
        self::assertNotTrue($unknown['co2_typical']);

        foreach ($this->search(filters: new FlightFilters(lowerCo2: true))['rows'] as $row) {
            self::assertNotSame('ZZZ', $row['legs'][0]['aircraft_code'], 'An unmeasured flight was filtered in as clean.');
        }
    }

    /**
     * @return array<string, float>
     */
    private function byAircraft(): array
    {
        $figures = [];

        foreach ($this->search()['rows'] as $row) {
            $figures[(string) $row['legs'][0]['aircraft_code']] = (float) $row['co2_kg'];
        }

        return $figures;
    }

    /** @return array<string, mixed> */
    private function search(SortMethod $sort = SortMethod::Price, ?FlightFilters $filters = null): array
    {
        return new FlightRepository($this->connection())->searchDirection(
            'YUL',
            'LHR',
            self::departDate(),
            $sort,
            0,
            20,
            CabinClass::Economy,
            $filters,
        );
    }

    private function insertFlight(string $airline, string $aircraft, string $departure, string $arrival): int
    {
        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, aircraft, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, cabins, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $airline, 100, $aircraft, 'YUL', self::departDate() . ' ' . $departure,
                'LHR', self::departDate() . ' ' . $arrival, self::DISTANCE_KM, 450, 1, 20.00, 3.00, 4.10,
            ],
        );
    }
}
