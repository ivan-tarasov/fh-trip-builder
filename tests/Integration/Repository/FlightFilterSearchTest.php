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

    // A long route nobody flies non-stop. A direct itinerary has no wait to
    // fall outside a layover range, so it satisfies every one — on a route with
    // directs the range never binds and a test of its ends proves nothing.
    private const CONNECTING_FROM = 'CMN';
    private const CONNECTING_TO = 'YVR';
    private const CONNECTING_DATE = '2026-09-23';

    // Each probe is a full search, so every option would cost minutes. Two per
    // dimension is enough to catch a wrong rule while keeping the suite quick.
    private const PROBE_LIMIT = 2;

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, cheapest: float|null, available: array<string, list<string>|list<int>|bool>, option_prices: array<string, array<array-key, float>>, bounds: array<string, array{min: int, max: int, floor_max: int, ceiling_min: int}>, highlights: array<string, array{price: float, duration: int}>}
     */
    private function search(?FlightFilters $filters = null): array
    {
        return $this->searchRoute(self::FROM, self::TO, self::DATE, $filters);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, cheapest: float|null, available: array<string, list<string>|list<int>|bool>, option_prices: array<string, array<array-key, float>>, bounds: array<string, array{min: int, max: int, floor_max: int, ceiling_min: int}>, highlights: array<string, array{price: float, duration: int}>}
     */
    private function searchRoute(string $from, string $to, string $date, ?FlightFilters $filters = null): array
    {
        return new FlightRepository($this->connection())
            ->searchDirection($from, $to, $date, SortMethod::Price, 0, 10, $filters);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, cheapest: float|null, available: array<string, list<string>|list<int>|bool>, option_prices: array<string, array<array-key, float>>, bounds: array<string, array{min: int, max: int, floor_max: int, ceiling_min: int}>, highlights: array<string, array{price: float, duration: int}>}
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

    public function testChoosingAnAirlineReturnsTripsThatUseIt(): void
    {
        $available = $this->requireResults()['available'];
        $offered = (array) $available[FlightFilters::DIM_AIRLINES];

        self::assertNotEmpty($offered, 'A route with results should offer airlines to filter by.');

        foreach (array_slice($offered, 0, self::PROBE_LIMIT) as $code) {
            $result = $this->search(new FlightFilters(airlines: [(string) $code]));

            self::assertGreaterThan(0, $result['total']);

            // Every itinerary returned flies at least one leg on that airline —
            // not necessarily all of them, which is what the single-carrier
            // toggle is for.
            foreach ($result['rows'] as $row) {
                $carriers = array_map(
                    static fn(array $leg): string => (string) $leg['carrier'],
                    $row['legs'],
                );

                self::assertContains($code, $carriers);
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

    public function testTheAdvertisedPriceIsThePriceYouGet(): void
    {
        // Each filter option shows the cheapest itinerary carrying it. If
        // picking the option returns something dearer, the sidebar is quoting a
        // price the search cannot honour.
        $result = $this->requireResults();
        $prices = $result['option_prices'];

        $probes = [
            FlightFilters::DIM_AIRLINES => static fn(string $v): FlightFilters => new FlightFilters(airlines: [$v]),
            FlightFilters::DIM_LAYOVER_AIRPORTS => static fn(string $v): FlightFilters => new FlightFilters(layoverAirports: [$v]),
            FlightFilters::DIM_STOPS => static fn(string $v): FlightFilters => new FlightFilters(stops: [(int) $v]),
        ];

        foreach ($probes as $dimension => $make) {
            $options = array_slice($prices[$dimension] ?? [], 0, self::PROBE_LIMIT, true);

            foreach ($options as $value => $advertised) {
                $chosen = $this->search($make((string) $value));

                self::assertNotNull(
                    $chosen['cheapest'],
                    sprintf('%s "%s" is priced but returns nothing', $dimension, $value),
                );
                self::assertEqualsWithDelta(
                    $advertised,
                    $chosen['cheapest'],
                    0.01,
                    sprintf('%s "%s" advertises %.2f but delivers %.2f', $dimension, $value, $advertised, $chosen['cheapest']),
                );
            }
        }
    }

    public function testAnUnavailableOptionIsNotPriced(): void
    {
        // A greyed option returns nothing, so there is no price to quote — a
        // number beside it would suggest it is choosable.
        $result = $this->requireResults();

        foreach ($result['option_prices'] as $dimension => $byValue) {
            $offered = array_map(strval(...), (array) ($result['available'][$dimension] ?? []));

            foreach (array_keys($byValue) as $value) {
                self::assertContains(
                    (string) $value,
                    $offered,
                    sprintf('%s "%s" is priced but not offered', $dimension, $value),
                );
            }
        }
    }

    public function testEveryPositionARangeHandleCanReachReturnsSomething(): void
    {
        $route = fn(?FlightFilters $filters = null): array => $this->searchRoute(
            self::CONNECTING_FROM,
            self::CONNECTING_TO,
            self::CONNECTING_DATE,
            $filters,
        );

        // The same contract as the lists above, for a slider: the sidebar must
        // not offer a position that can only answer "no flights".
        //
        // A range over layovers is the awkward case. It reads every wait in an
        // itinerary and asks that they all fall inside, so the shortest wait on
        // the route is not a usable ceiling — the itinerary it belongs to has
        // longer waits too, and a ceiling down there matches nothing. The ends
        // have to be measured the way the filter reads them.
        $all = $route();

        if ($all['total'] === 0) {
            self::markTestSkipped('No generated flights on the connecting route (run flights:add).');
        }

        $bound = $all['bounds'][FlightFilters::DIM_LAYOVER_RANGE] ?? null;

        if ($bound === null) {
            self::markTestSkipped('No connections on this route to measure a layover range against.');
        }

        self::assertGreaterThanOrEqual($bound['min'], $bound['ceiling_min']);
        self::assertLessThanOrEqual($bound['max'], $bound['ceiling_min']);
        self::assertGreaterThanOrEqual($bound['min'], $bound['floor_max']);
        self::assertLessThanOrEqual($bound['max'], $bound['floor_max']);

        foreach ([
            'lowest ceiling' => [$bound['min'], $bound['ceiling_min']],
            'highest floor' => [$bound['floor_max'], $bound['max']],
            'the whole range' => [$bound['min'], $bound['max']],
        ] as $label => [$from, $to]) {
            self::assertGreaterThan(
                0,
                $route(new FlightFilters(layoverRange: [$from, $to]))['total'],
                sprintf('%s on offer (%d-%d minutes) returns nothing', $label, $from, $to),
            );
        }

        // And the stops are where they are for a reason: a step past either one
        // is the empty result they exist to keep a handle out of. Without this
        // the test would still pass with the ends left wide open.
        if ($bound['ceiling_min'] > $bound['min']) {
            self::assertSame(
                0,
                $route(new FlightFilters(layoverRange: [$bound['min'], $bound['ceiling_min'] - 1]))['total'],
                'a ceiling below the lowest on offer should be the empty result the stop guards against',
            );
        }

        if ($bound['floor_max'] < $bound['max']) {
            self::assertSame(
                0,
                $route(new FlightFilters(layoverRange: [$bound['floor_max'] + 1, $bound['max']]))['total'],
                'a floor above the highest on offer should be the empty result the stop guards against',
            );
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
