<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The layover slider's ceiling stop, on a route that also sells a direct.
 *
 * `FlightFilterSearchTest::testEveryPositionARangeHandleCanReachReturnsSomething`
 * has been failing intermittently in CI -- `lowest ceiling on offer (201-201
 * minutes) returns nothing` on develop, `(174-174)` on a later branch. It was
 * not flaky. It was finding a real defect on the days the generator happened
 * to put a direct flight on the connecting route it searches, and passing on
 * the days it did not.
 *
 * Three places stated the same belief, and one of them was already false:
 *
 *   - `FlightRepository::bounds()` treated a direct flight as meeting any
 *     ceiling, and so let the ceiling handle travel all the way down to `min`.
 *   - `FlightFilterSearchTest`'s route comment said a direct itinerary
 *     "satisfies every one" range.
 *   - `FlightFilters::waitsWithin()` says a flight with no waits passes only
 *     when the range has *no floor*: answering "layovers of six hours" with a
 *     flight that has none is not an answer.
 *
 * The last of those is right, and it and the first arrived in the same commit
 * (5e1560b). That commit fixed the matching half of the pair -- a direct flight
 * cannot meet a floor, so `floor_max` stopped being relaxed -- and left the
 * ceiling half relaxed on reasoning that only holds for a ceiling with no floor
 * under it. The control never submits one: `sidebar/slider.html.twig` writes
 * `value="{from}-{to}"`, so the floor handle's position always rides along, and
 * an untouched floor sits at `min` rather than at nothing.
 *
 * So the ceiling handle was offered a stretch of track that could only ever
 * return an empty page, which is the one thing these bounds exist to prevent.
 *
 * This fixture is the failing case made deterministic: a route where the only
 * connecting itinerary has two waits, so the shortest wait on the route is not
 * a reachable ceiling, plus a direct flight to trip the old branch. The dates
 * are in 2027, past anything `flights:add` generates, so these four rows are
 * the whole candidate set.
 */
final class LayoverRangeWithADirectFlightTest extends IntegrationTestCase
{
    // Four cities with one airport each, so a search for the city resolves to
    // exactly the airport the fixture names and no sibling joins in. All four
    // are enabled and carry traffic, which resolveAirportCodes() requires --
    // an airport the network does not serve resolves to nothing and the search
    // returns before it reaches the fixture.
    private const FROM = 'ABV';
    private const VIA_ONE = 'ACC';
    private const VIA_TWO = 'ADD';
    private const TO = 'ABJ';

    private const DATE = '2027-03-15';

    // Both inside the 45-360 minute connection window, and far enough apart
    // that `min` and the lowest usable ceiling cannot coincide.
    private const SHORT_WAIT = 60;
    private const LONG_WAIT = 300;

    /** @var list<int> */
    private array $inserted = [];

    protected function setUp(): void
    {
        // The connecting itinerary: two waits, 60 minutes then 300.
        $this->insertFlight(self::FROM, '06:00:00', self::VIA_ONE, '07:00:00');
        $this->insertFlight(self::VIA_ONE, '08:00:00', self::VIA_TWO, '09:00:00');
        $this->insertFlight(self::VIA_TWO, '14:00:00', self::TO, '15:00:00');

        // And the direct flight, which is what used to relax the ceiling.
        $this->insertFlight(self::FROM, '08:00:00', self::TO, '12:00:00');
    }

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null || $this->inserted === []) {
            return;
        }

        $this->connection()->execute(
            'DELETE FROM flights WHERE id IN (' . implode(', ', array_fill(0, count($this->inserted), '?')) . ')',
            $this->inserted,
        );
    }

    /**
     * The fixture is the whole candidate set, and it is the shape that matters.
     */
    public function testTheRouteOffersOneConnectionWithTwoWaitsAndOneDirect(): void
    {
        $result = $this->search();

        self::assertSame(2, $result['total'], 'the fixture should be the only thing on this route');

        // A page row is hydrated and carries its stop count rather than the
        // stamps the bounds are measured from, so the shape is read off that.
        $stops = array_map(static fn(array $row): int => (int) $row['stops'], $result['rows']);
        sort($stops);

        self::assertSame([0, 2], $stops, 'one direct, and one itinerary with two waits');
    }

    /**
     * The stop is the connecting itinerary's *longest* wait, not the route's
     * shortest, and the direct flight does not move it.
     */
    public function testTheCeilingStopsAtTheShortestLongestWait(): void
    {
        $bound = $this->bound();

        self::assertSame(self::SHORT_WAIT, $bound['min']);
        self::assertSame(self::LONG_WAIT, $bound['max']);
        self::assertSame(
            self::LONG_WAIT,
            $bound['ceiling_min'],
            'a direct flight is excluded by any range carrying a floor, so it cannot lower the ceiling stop',
        );
    }

    /**
     * The contract, on the range the control actually submits.
     */
    public function testTheLowestCeilingOnOfferReturnsSomething(): void
    {
        $bound = $this->bound();

        self::assertGreaterThan(
            0,
            $this->search(new FlightFilters(layoverRange: [$bound['min'], $bound['ceiling_min']]))['total'],
            sprintf('%d-%d minutes should not be an empty page', $bound['min'], $bound['ceiling_min']),
        );
    }

    /**
     * And a step below it is the empty result the stop exists to fence off --
     * without this the ceiling could be left wide open and still pass.
     */
    public function testAStepBelowTheStopIsEmpty(): void
    {
        $bound = $this->bound();

        self::assertSame(
            0,
            $this->search(new FlightFilters(layoverRange: [$bound['min'], $bound['ceiling_min'] - 1]))['total'],
        );
    }

    /**
     * The other end, pinned for the same reason.
     *
     * A direct flight cannot meet a floor either, and commit 5e1560b already
     * stopped `floor_max` being relaxed to `max` for one. Asserted here so a
     * later tidy-up cannot put the two ends back in step by relaxing both --
     * which is the shape this bug had before that commit.
     */
    public function testTheFloorStopsAtTheLongestShortestWait(): void
    {
        $bound = $this->bound();

        self::assertSame(self::SHORT_WAIT, $bound['floor_max']);

        self::assertSame(
            0,
            $this->search(new FlightFilters(layoverRange: [$bound['floor_max'] + 1, $bound['max']]))['total'],
            'a floor above the longest shortest wait is the empty result the stop guards against',
        );
    }

    /**
     * The rule the bounds used to disagree with, asserted on its own.
     *
     * A range wide enough to hold everything on the route still returns one
     * itinerary and not two: the direct flight has no wait to be 60 minutes
     * long, and a floor is a question it cannot answer.
     */
    public function testAFlooredRangeExcludesTheDirectFlightEvenWideOpen(): void
    {
        $bound = $this->bound();

        self::assertSame(
            1,
            $this->search(new FlightFilters(layoverRange: [$bound['min'], $bound['max']]))['total'],
        );

        // With no floor it comes back, which is the case the old branch was
        // reasoning about -- reachable from a hand-written URL, never from the
        // slider.
        self::assertSame(
            2,
            $this->search(new FlightFilters(layoverRange: [null, $bound['max']]))['total'],
        );
    }

    /**
     * @return array{min: int, max: int, floor_max: int, ceiling_min: int}
     */
    private function bound(): array
    {
        $bound = $this->search()['bounds'][FlightFilters::DIM_LAYOVER_RANGE] ?? null;

        self::assertNotNull($bound, 'the fixture connects, so there is a layover range to measure');

        return $bound;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, cheapest: float|null, available: array<string, list<string>|list<int>|bool>, option_prices: array<string, array<array-key, float>>, bounds: array<string, array{min: int, max: int, floor_max: int, ceiling_min: int}>, highlights: array<string, array{price: float, duration: int}>}
     */
    private function search(?FlightFilters $filters = null): array
    {
        return new FlightRepository($this->connection())
            ->searchDirection(self::FROM, self::TO, self::DATE, SortMethod::Price, 0, 10, CabinClass::Economy, $filters);
    }

    private function insertFlight(string $from, string $departure, string $to, string $arrival): void
    {
        $this->inserted[] = $this->connection()->insert(
            'INSERT INTO flights (airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['AC', 100, $from, self::DATE . ' ' . $departure, $to, self::DATE . ' ' . $arrival, 300, 60, 20.00, 3.00, 4.10],
        );
    }
}
