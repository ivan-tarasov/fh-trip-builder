<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Party;
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
    private const CabinClass CABIN = CabinClass::Economy;

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
        // Nothing was written, so there is nothing to tidy -- and a skip
        // raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ([[self::FROM, self::TO], ['ZZZ', 'ZZY'], ['LON', 'NYC'], ['LHR', 'JFK']] as [$from, $to]) {
            $this->forget($from, $to);
        }
    }

    public function testARouteIsUnbuiltUntilItIsBuilt(): void
    {
        $this->forget('ZZZ', 'ZZY');

        self::assertNull($this->prices->builtAt('ZZZ', 'ZZY', self::CABIN));
        self::assertTrue($this->prices->isStale('ZZZ', 'ZZY', self::CABIN, 24));
    }

    public function testBuildingARouteWithNoFlightsStillRecordsThatItWasTried(): void
    {
        // Otherwise every visitor who opens the calendar on a route that has no
        // fares pays to find that out again.
        $this->forget('ZZZ', 'ZZY');
        $this->prices->build('ZZZ', 'ZZY', self::CABIN, $this->since, $this->until);

        self::assertNotNull($this->prices->builtAt('ZZZ', 'ZZY', self::CABIN), 'the attempt should be recorded');
        self::assertSame([], $this->prices->read('ZZZ', 'ZZY', self::CABIN, $this->since, $this->until));
        self::assertFalse($this->prices->isStale('ZZZ', 'ZZY', self::CABIN, 24), 'and it should not be tried again at once');
    }

    public function testARealRouteIsPricedAndReadsBackByDate(): void
    {
        $this->prices->build(self::FROM, self::TO, self::CABIN, $this->since, $this->until);
        $prices = $this->prices->read(self::FROM, self::TO, self::CABIN, $this->since, $this->until);

        self::assertNotSame([], $prices, 'this route has flights, so it should have fares');

        foreach ($prices as $date => $price) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
            self::assertArrayHasKey('base', $price);
            self::assertArrayHasKey('tax', $price);
            self::assertGreaterThan(0, $price['base']);
            self::assertGreaterThanOrEqual(0, $price['tax']);
            self::assertGreaterThanOrEqual($this->since, $date);
            self::assertLessThan($this->until, $date);
        }
    }

    public function testReadingIsBoundedByTheWindowAsked(): void
    {
        $this->prices->build(self::FROM, self::TO, self::CABIN, $this->since, $this->until);

        $narrow = date('Y-m-d', strtotime('+10 day'));
        $prices = $this->prices->read(self::FROM, self::TO, self::CABIN, $this->since, $narrow);

        foreach (array_keys($prices) as $date) {
            self::assertLessThan($narrow, $date);
        }
    }

    public function testBuildingAgainReplacesRatherThanAccumulates(): void
    {
        // The build deletes before it inserts, so a route whose flights have
        // thinned out does not keep the days it used to have.
        $this->prices->build(self::FROM, self::TO, self::CABIN, $this->since, $this->until);
        $first = $this->prices->read(self::FROM, self::TO, self::CABIN, $this->since, $this->until);

        $this->prices->build(self::FROM, self::TO, self::CABIN, $this->since, $this->until);
        $second = $this->prices->read(self::FROM, self::TO, self::CABIN, $this->since, $this->until);

        self::assertSame($first, $second);
    }

    public function testACityCodeIsPricedAsAllItsAirports(): void
    {
        // A price filed under LON has to be the price a search for LON finds,
        // and a search for LON looks at every London airport.
        $this->prices->build('LON', 'NYC', self::CABIN, $this->since, $this->until);
        $city = $this->prices->read('LON', 'NYC', self::CABIN, $this->since, $this->until);

        $this->prices->build('LHR', 'JFK', self::CABIN, $this->since, $this->until);
        $single = $this->prices->read('LHR', 'JFK', self::CABIN, $this->since, $this->until);

        self::assertNotSame([], $city);

        // Every day the single pair can offer, the city can offer at least as
        // cheaply -- it is choosing from a superset of the same flights.
        foreach ($single as $date => $price) {
            self::assertArrayHasKey($date, $city, $date . ' is priced LHR-JFK but not LON-NYC');
            self::assertLessThanOrEqual(
                self::total($price),
                self::total($city[$date]),
                'the city should never be dearer on ' . $date,
            );
        }
    }

    public function testACabinWithAnUpliftIsPricedAboveEconomy(): void
    {
        // The whole reason cabin is in the key. Business carries an uplift that
        // scales with haul, and not every flight sells it, so its cheapest day
        // is a different number from economy's -- and on some days a different
        // day entirely.
        $this->prices->build(self::FROM, self::TO, CabinClass::Economy, $this->since, $this->until);
        $this->prices->build(self::FROM, self::TO, CabinClass::Business, $this->since, $this->until);

        $economy = $this->prices->read(self::FROM, self::TO, CabinClass::Economy, $this->since, $this->until);
        $business = $this->prices->read(self::FROM, self::TO, CabinClass::Business, $this->since, $this->until);

        self::assertNotSame([], $economy);
        self::assertNotSame([], $business);
        self::assertNotEquals($economy, $business, 'business should not be priced as economy');

        // Every day business is sold on, it costs more than the economy seat.
        foreach ($business as $date => $price) {
            self::assertArrayHasKey($date, $economy, $date . ' has business but no economy fare');
            self::assertGreaterThan(
                self::total($economy[$date]),
                self::total($price),
                'business should cost more on ' . $date,
            );
        }
    }

    public function testTheTwoCabinsAreCachedApart(): void
    {
        $this->prices->build(self::FROM, self::TO, CabinClass::Economy, $this->since, $this->until);

        // Building one must not answer for the other.
        self::assertNotNull($this->prices->builtAt(self::FROM, self::TO, CabinClass::Economy));
        self::assertNull($this->prices->builtAt(self::FROM, self::TO, CabinClass::First));
        self::assertTrue($this->prices->isStale(self::FROM, self::TO, CabinClass::First, 24));
    }

    public function testTheTwoPartsPriceAPartyTheWayPartyDoes(): void
    {
        // Why they are stored apart at all. A child pays three quarters of the
        // fare but a whole adult's tax and an infant a tenth and none, so the
        // halves scale differently -- a single stored total could not be turned
        // into what a family pays, which is how one adult and nine came to show
        // the same figure.
        $this->prices->build(self::FROM, self::TO, self::CABIN, $this->since, $this->until);
        $prices = $this->prices->read(self::FROM, self::TO, self::CABIN, $this->since, $this->until);
        $day = $prices[array_key_first($prices)];

        $alone = new Party(adults: 1);
        $family = new Party(adults: 2, children: 1, infants: 1);

        $forOne = $alone->apply($day['base'], $day['tax']);
        $forFamily = $family->apply($day['base'], $day['tax']);

        self::assertEqualsWithDelta($day['base'] + $day['tax'], $forOne['base'] + $forOne['tax'], 0.01);

        // Two adults, a child at three quarters and an infant at a tenth.
        self::assertEqualsWithDelta($day['base'] * 2.85, $forFamily['base'], 0.01);
        // And three taxed seats, the lap infant paying none.
        self::assertEqualsWithDelta($day['tax'] * 3.0, $forFamily['tax'], 0.01);
    }

    /** @param array{base: float, tax: float} $price */
    private static function total(array $price): float
    {
        return $price['base'] + $price['tax'];
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
