<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * Filtering and availability over a real search.
 *
 * The contract that matters: an option the sidebar offers must return
 * something when chosen. Getting that wrong is worse than having no
 * availability at all — the control looks usable and empties the page.
 */
final class FlightFilterSearchTest extends IntegrationTestCase
{
    private const FROM = 'PAR';
    private const TO = 'NYC';
    private const DATE = '2026-09-15';

    // Each probe is a full search, so every option would cost minutes. Two per
    // dimension is enough to catch a wrong rule while keeping the suite quick.
    private const PROBE_LIMIT = 2;

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, cheapest: float|null, available: array<string, list<string>|list<int>|bool>}
     */
    private function search(?FlightFilters $filters = null): array
    {
        return new FlightRepository($this->connection())
            ->searchDirection(self::FROM, self::TO, self::DATE, SortMethod::Price, 1, $filters);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, cheapest: float|null, available: array<string, list<string>|list<int>|bool>}
     */
    private function requireResults(): array
    {
        $result = $this->search();

        if ($result['total'] === 0) {
            self::markTestSkipped('No generated flights on this route (run flights:add).');
        }

        return $result;
    }

    public function testAnUnfilteredSearchIsUnchanged(): void
    {
        $result = $this->requireResults();

        self::assertNotEmpty($result['rows']);
        self::assertNotNull($result['cheapest']);
        self::assertLessThanOrEqual(10, count($result['rows']));
    }

    public function testFilteringNeverWidensTheResultSet(): void
    {
        $all = $this->requireResults();

        foreach ([
            new FlightFilters(stops: [0]),
            new FlightFilters(maxDuration: 720),
            new FlightFilters(noNightLayover: true),
            new FlightFilters(singleCarrier: true),
        ] as $filters) {
            self::assertLessThanOrEqual($all['total'], $this->search($filters)['total']);
        }
    }

    public function testEveryOfferedOptionReturnsSomething(): void
    {
        $available = $this->requireResults()['available'];

        $probes = [
            FlightFilters::DIM_AIRLINES => static fn(string $v): FlightFilters => new FlightFilters(airlines: [$v]),
            FlightFilters::DIM_LAYOVER_AIRPORTS => static fn(string $v): FlightFilters => new FlightFilters(layoverAirports: [$v]),
            FlightFilters::DIM_AIRCRAFT => static fn(string $v): FlightFilters => new FlightFilters(aircraft: [$v]),
            FlightFilters::DIM_DEPART_AIRPORTS => static fn(string $v): FlightFilters => new FlightFilters(departAirports: [$v]),
            FlightFilters::DIM_ARRIVE_AIRPORTS => static fn(string $v): FlightFilters => new FlightFilters(arriveAirports: [$v]),
            FlightFilters::DIM_ARRIVE_DATE => static fn(string $v): FlightFilters => new FlightFilters(arriveDates: [$v]),
            FlightFilters::DIM_DEPART_TIME => static fn(string $v): FlightFilters => new FlightFilters(departBuckets: [$v]),
        ];

        foreach ($probes as $dimension => $make) {
            $options = array_slice((array) ($available[$dimension] ?? []), 0, self::PROBE_LIMIT);

            foreach ($options as $option) {
                self::assertGreaterThan(
                    0,
                    $this->search($make((string) $option))['total'],
                    sprintf('"%s" was offered for %s but returns nothing', $option, $dimension),
                );
            }
        }
    }

    public function testAnAirlineIsOfferedOnlyWhenItCanFlyTheWholeTrip(): void
    {
        // The rule that made this test worth writing: an airline flying one leg
        // of a two-carrier connection must not be offered, because selecting it
        // demands every leg and would return nothing.
        $available = $this->requireResults()['available'];
        $offered = (array) $available[FlightFilters::DIM_AIRLINES];

        self::assertNotEmpty($offered);

        foreach (array_slice($offered, 0, self::PROBE_LIMIT) as $code) {
            $result = $this->search(new FlightFilters(airlines: [(string) $code]));

            self::assertGreaterThan(0, $result['total']);

            // And what comes back really is flown end to end by that airline.
            foreach ($result['rows'] as $row) {
                $carriers = array_unique(array_map(
                    static fn(array $leg): string => (string) $leg['carrier'],
                    $row['legs'],
                ));

                self::assertSame([$code], array_values($carriers));
            }
        }
    }

    public function testAvailabilityDoesNotCollapseTheDimensionBeingChosen(): void
    {
        // Picking one airline must not leave that airline as the only option —
        // otherwise the list becomes un-editable once you touch it.
        $offered = (array) $this->requireResults()['available'][FlightFilters::DIM_AIRLINES];

        if (count($offered) < 2) {
            self::markTestSkipped('Route has only one selectable airline.');
        }

        $chosen = (string) $offered[0];
        $after = (array) $this->search(new FlightFilters(airlines: [$chosen]))['available'][FlightFilters::DIM_AIRLINES];

        self::assertContains($chosen, $after);
        self::assertGreaterThan(1, count($after));
    }

    public function testCheapestAndTotalFollowTheFilteredSet(): void
    {
        $all = $this->requireResults();
        $direct = $this->search(new FlightFilters(stops: [0]));

        if ($direct['total'] === 0) {
            self::markTestSkipped('No direct flights on this route.');
        }

        // A filtered search reports its own cheapest, not the whole search's.
        self::assertGreaterThanOrEqual($all['cheapest'], $direct['cheapest']);

        foreach ($direct['rows'] as $row) {
            self::assertSame(0, $row['stops']);
            self::assertGreaterThanOrEqual($direct['cheapest'] - 0.01, $row['price_base'] + $row['price_tax']);
        }
    }

    public function testAnImpossibleCombinationEmptiesCleanly(): void
    {
        // Two stops, but only one airport allowed to connect at.
        $result = $this->search(new FlightFilters(stops: [2], layoverAirports: ['ZZZ']));

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['rows']);
        self::assertNull($result['cheapest']);

        // Availability still comes back, so the sidebar can show the way out.
        self::assertNotEmpty($result['available']);
    }
}
