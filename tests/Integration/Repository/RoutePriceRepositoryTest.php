<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Config;
use TripBuilder\Repository\RoutePriceRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The calendar's per-day fares, which are cached because they cannot be worked
 * out in time.
 *
 * Measured on this data: the cheapest one-connection fare across a calendar
 * runs 71ms on a quiet route and 4.7s on a busy one, and the query will not be
 * argued with -- MySQL drives it from a full scan of the flights table, and
 * pinning the other join order made it slower still. So it is built once and
 * read back, and what these cover is that the building and the reading agree.
 */
final class RoutePriceRepositoryTest extends IntegrationTestCase
{
    private const string FROM = 'YUL';
    private const string TO = 'LHR';

    private RoutePriceRepository $prices;
    private string $since;
    private string $until;

    protected function setUp(): void
    {
        new Config('common');

        $this->prices = new RoutePriceRepository($this->connection());
        $this->since = date('Y-m-d');
        $this->until = date('Y-m-d', strtotime('+60 day'));
    }

    /**
     * Forget every route this test built.
     *
     * These tests build over a shorter window than the app does, and the table
     * they build into is the one the app reads: left behind, a route would keep
     * the sixty days a test wanted rather than the ninety a calendar shows, and
     * the app would not know to look again until they went stale. A cache is
     * safe to throw away, so it is thrown away.
     */
    protected function tearDown(): void
    {
        foreach ([[self::FROM, self::TO], ['ZZZ', 'ZZY'], ['LON', 'NYC'], ['LHR', 'JFK']] as [$from, $to]) {
            $this->forget($from, $to);
        }
    }

    public function testARouteIsUnbuiltUntilItIsBuilt(): void
    {
        $this->forget('ZZZ', 'ZZY');

        self::assertNull($this->prices->builtAt('ZZZ', 'ZZY'));
        self::assertTrue($this->prices->isStale('ZZZ', 'ZZY', 24));
    }

    public function testBuildingARouteWithNoFlightsStillRecordsThatItWasTried(): void
    {
        // Otherwise every visitor who opens the calendar on a route that has no
        // fares pays to find that out again.
        $this->forget('ZZZ', 'ZZY');
        $this->prices->build('ZZZ', 'ZZY', $this->since, $this->until);

        self::assertNotNull($this->prices->builtAt('ZZZ', 'ZZY'), 'the attempt should be recorded');
        self::assertSame([], $this->prices->read('ZZZ', 'ZZY', $this->since, $this->until));
        self::assertFalse($this->prices->isStale('ZZZ', 'ZZY', 24), 'and it should not be tried again at once');
    }

    public function testARealRouteIsPricedAndReadsBackByDate(): void
    {
        $this->prices->build(self::FROM, self::TO, $this->since, $this->until);
        $prices = $this->prices->read(self::FROM, self::TO, $this->since, $this->until);

        self::assertNotSame([], $prices, 'this route has flights, so it should have fares');

        foreach ($prices as $date => $price) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
            self::assertGreaterThan(0, $price);
            self::assertGreaterThanOrEqual($this->since, $date);
            self::assertLessThan($this->until, $date);
        }
    }

    public function testReadingIsBoundedByTheWindowAsked(): void
    {
        $this->prices->build(self::FROM, self::TO, $this->since, $this->until);

        $narrow = date('Y-m-d', strtotime('+10 day'));
        $prices = $this->prices->read(self::FROM, self::TO, $this->since, $narrow);

        foreach (array_keys($prices) as $date) {
            self::assertLessThan($narrow, $date);
        }
    }

    public function testBuildingAgainReplacesRatherThanAccumulates(): void
    {
        // The build deletes before it inserts, so a route whose flights have
        // thinned out does not keep the days it used to have.
        $this->prices->build(self::FROM, self::TO, $this->since, $this->until);
        $first = $this->prices->read(self::FROM, self::TO, $this->since, $this->until);

        $this->prices->build(self::FROM, self::TO, $this->since, $this->until);
        $second = $this->prices->read(self::FROM, self::TO, $this->since, $this->until);

        self::assertSame($first, $second);
    }

    public function testACityCodeIsPricedAsAllItsAirports(): void
    {
        // A price filed under LON has to be the price a search for LON finds,
        // and a search for LON looks at every London airport.
        $this->prices->build('LON', 'NYC', $this->since, $this->until);
        $city = $this->prices->read('LON', 'NYC', $this->since, $this->until);

        $this->prices->build('LHR', 'JFK', $this->since, $this->until);
        $single = $this->prices->read('LHR', 'JFK', $this->since, $this->until);

        self::assertNotSame([], $city);

        // Every day the single pair can offer, the city can offer at least as
        // cheaply -- it is choosing from a superset of the same flights.
        foreach ($single as $date => $price) {
            self::assertArrayHasKey($date, $city, $date . ' is priced LHR-JFK but not LON-NYC');
            self::assertLessThanOrEqual($price, $city[$date], 'the city should never be dearer on ' . $date);
        }
    }

    private function forget(string $from, string $to): void
    {
        $this->connection()->execute(
            'DELETE FROM route_day_price WHERE from_code = ? AND to_code = ?',
            [$from, $to],
        );
        $this->connection()->execute(
            'DELETE FROM route_price_build WHERE from_code = ? AND to_code = ?',
            [$from, $to],
        );
    }
}
