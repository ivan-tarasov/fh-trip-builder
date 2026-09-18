<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Noah\Flights\FarePricing;
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
 *
 * **These flights are this test's own.** They used to read whatever
 * `flights:add` happened to generate, and the generator is random: the network
 * is sparse on any one route -- `YUL -> LHR` had fourteen direct flights across
 * sixty days when this was written -- so on some runs a route had nothing in
 * the window and a "this route has flights" guard failed. It failed on MySQL
 * while MariaDB passed the same commit, on a branch that touched the admin
 * panel and nothing else, and a re-run of the failed job passed. A test that
 * fails one run in N on data nobody chose is a test that gets ignored.
 *
 * Priced far below anything the generator can make, which is not a guess:
 * `FarePricing` starts at `FIXED_DOLLARS` and varies from 90%, so no generated
 * fare is below $27. `testTheFixturesOutpriceAnythingGenerated` pins that, so
 * the day the pricing floor moves this file says so rather than going quietly
 * flaky again.
 *
 * The route is still a real one, so the shape checks below run over generated
 * days as well as the fixtures. That they no longer *depend* on them was
 * measured rather than assumed: pointed at `YOW -> ABJ`, a pair the generator
 * connects with nothing at all, every case here still passes.
 */
final class RoutePriceRepositoryTest extends IntegrationTestCase
{
    private const string FROM = 'YUL';
    private const string TO = 'LHR';
    private const CabinClass CABIN = CabinClass::Economy;

    /**
     * Days into the window the fixtures sit on.
     *
     * Inside the sixty days these tests build, and spread so that each one can
     * be named in an assertion rather than reached through
     * `array_key_first()` -- which used to mean the assertion was about
     * whichever day the generator happened to fill.
     */
    private const int PLAIN_DAY = 3;
    private const int PREMIUM_DAY = 5;
    private const int CITY_DAY = 7;

    /**
     * Roughly Montreal to London, and the distance matters here: the cabin
     * uplift scales with haul, so a figure worth asserting needs a leg long
     * enough to carry most of it.
     */
    private const int FIXTURE_KM = 5220;

    /**
     * Cheap enough that nothing generated can undercut them.
     *
     * See `testTheFixturesOutpriceAnythingGenerated`, which is what stops this
     * being an assumption.
     */
    private const float FIXTURE_BASE = 5.00;
    private const float FIXTURE_TAX = 1.00;

    /** The one that sells business, and is dearer in economy than the plain one. */
    private const float PREMIUM_BASE = 6.00;
    private const float PREMIUM_TAX = 1.00;

    private RoutePriceRepository $prices;
    private string $since;
    private string $until;

    /** @var list<int> */
    private array $flights = [];

    protected function setUp(): void
    {
        new Config('common');

        $this->prices = new RoutePriceRepository($this->connection());
        $this->since = date('Y-m-d');
        $this->until = date('Y-m-d', strtotime('+60 day'));

        if ($this->connectionOrNull() === null) {
            return;
        }

        // A plain day: one flight, economy only.
        $this->flights[] = $this->insertFlight(self::FROM, self::TO, self::PLAIN_DAY, CabinClass::Economy->bit());

        // And a day holding both, which is what makes the cabin test say
        // something: the cheapest economy seat and the cheapest business seat
        // are on two different aeroplanes.
        $this->flights[] = $this->insertFlight(self::FROM, self::TO, self::PREMIUM_DAY, CabinClass::Economy->bit());
        $this->flights[] = $this->insertFlight(
            self::FROM,
            self::TO,
            self::PREMIUM_DAY,
            CabinClass::Economy->bit() | CabinClass::Business->bit(),
            self::PREMIUM_BASE,
            self::PREMIUM_TAX,
        );

        // One London airport to one New York airport, for the city test: LON
        // and NYC both expand to include this pair.
        $this->flights[] = $this->insertFlight('LHR', 'JFK', self::CITY_DAY, CabinClass::Economy->bit());
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

        foreach ($this->flights as $id) {
            $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$id]);
        }
    }

    /**
     * The assumption every other case here rests on, made a test.
     *
     * The fixtures are only the cheapest itinerary of their day because nothing
     * the generator writes can go below `FIXED_DOLLARS` less the bottom of its
     * variance. Move that floor and this fails here, rather than turning four
     * other cases into assertions about somebody else's flights.
     */
    public function testTheFixturesOutpriceAnythingGenerated(): void
    {
        $floor = FarePricing::FIXED_DOLLARS * min(FarePricing::VARIANCE_PERCENT) / 100;

        self::assertGreaterThan(
            self::PREMIUM_BASE + self::PREMIUM_TAX,
            $floor,
            'a generated fare can now undercut the fixtures, so these tests are measuring the generator again',
        );
        self::assertGreaterThan(self::FIXTURE_BASE + self::FIXTURE_TAX, self::PREMIUM_BASE + self::PREMIUM_TAX);
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

        // Named, not merely non-empty: this route has a flight on this day
        // because this test put one there.
        self::assertArrayHasKey(self::day(self::PLAIN_DAY), $prices);
        self::assertEqualsWithDelta(
            self::FIXTURE_BASE + self::FIXTURE_TAX,
            self::total($prices[self::day(self::PLAIN_DAY)]),
            0.01,
            'something undercut the fixture, so this is pricing the generated network',
        );

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

        $narrow = self::day(self::PLAIN_DAY + 1);
        $prices = $this->prices->read(self::FROM, self::TO, self::CABIN, $this->since, $narrow);

        // A day inside the narrow window and a day outside it, so the loop
        // below is proving something rather than running zero times.
        self::assertArrayHasKey(self::day(self::PLAIN_DAY), $prices);
        self::assertArrayNotHasKey(self::day(self::PREMIUM_DAY), $prices);

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

        self::assertNotSame([], $first, 'nothing was built, so this compares two empty answers');
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

        // The fixture is a flight this test put on LHR -> JFK, so the pair has
        // a day and the city has to have it too.
        self::assertArrayHasKey(self::day(self::CITY_DAY), $single);
        self::assertArrayHasKey(self::day(self::CITY_DAY), $city);

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
        // is a different number from economy's.
        //
        // Which is what the fixtures make true rather than hope for: on
        // PREMIUM_DAY there are two flights, and the cheaper one does not sell
        // business at all. The economy figure is therefore one aeroplane's and
        // the business figure is the other's.
        $this->prices->build(self::FROM, self::TO, CabinClass::Economy, $this->since, $this->until);
        $this->prices->build(self::FROM, self::TO, CabinClass::Business, $this->since, $this->until);

        $economy = $this->prices->read(self::FROM, self::TO, CabinClass::Economy, $this->since, $this->until);
        $business = $this->prices->read(self::FROM, self::TO, CabinClass::Business, $this->since, $this->until);

        $day = self::day(self::PREMIUM_DAY);

        self::assertArrayHasKey($day, $economy);
        self::assertArrayHasKey($day, $business);
        self::assertNotEquals($economy, $business, 'business should not be priced as economy');

        // The cheaper aeroplane, which sells no business seat.
        self::assertEqualsWithDelta(self::FIXTURE_BASE + self::FIXTURE_TAX, self::total($economy[$day]), 0.01);

        // The dearer one, at the multiplier CabinClass works out in PHP. The
        // SQL builds its own copy of that arithmetic, so this is where the two
        // would be caught disagreeing.
        self::assertEqualsWithDelta(
            (self::PREMIUM_BASE + self::PREMIUM_TAX) * CabinClass::Business->priceMultiplier(self::FIXTURE_KM),
            self::total($business[$day]),
            0.01,
            'the cached business fare is not the PHP multiplier over the same flight',
        );

        // And a day whose only flight sells economy alone is priced in economy
        // and left out of business -- "not every flight sells it", stated.
        self::assertArrayHasKey(self::day(self::PLAIN_DAY), $economy);

        // Every day business is sold on, it costs more than the economy seat.
        // True of any data, generated or not: the cheapest economy fare is at
        // worst the economy fare of the flight the business figure came from,
        // and the multiplier is above one.
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

        // A day this test put a flight on, rather than whichever day came
        // first out of the network.
        self::assertArrayHasKey(self::day(self::PLAIN_DAY), $prices);

        $day = $prices[self::day(self::PLAIN_DAY)];

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

    /** A day inside the window, as the table spells it. */
    private static function day(int $days): string
    {
        return date('Y-m-d', (int) strtotime('+' . $days . ' day'));
    }

    /**
     * One flight this test owns, cheap enough to be its day's own answer.
     *
     * Departing mid-morning and arriving the same day, so the date the price is
     * filed under is the date this asked for -- `cheapestPerDay()` groups on
     * `DATE(departure_time)`.
     */
    private function insertFlight(
        string $from,
        string $to,
        int $days,
        int $cabins,
        float $base = self::FIXTURE_BASE,
        float $tax = self::FIXTURE_TAX,
    ): int {
        $date = self::day($days);

        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, aircraft, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, cabins, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'AC', 100, '789', $from, $date . ' 09:00:00',
                $to, $date . ' 20:30:00', self::FIXTURE_KM, 450, $cabins, $base, $tax, 4.10,
            ],
        );
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

    /**
     * `cheapest()` (C6, #155) reads `route_day_price` directly rather than
     * through a build, so these insert rows straight into it -- the same
     * table `read()` above is tested against, on a route these fixtures own
     * rather than one built from flights.
     */
    public function testCheapestFindsTheLowestTotalAndItsDay(): void
    {
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(10), 100.00, 20.00);
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(20), 60.00, 15.00);
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(30), 90.00, 10.00);

        try {
            $cheapest = $this->prices->cheapest('ZZQ', 'ZZR', self::CABIN, $this->since);

            self::assertNotNull($cheapest);
            self::assertSame(self::day(20), $cheapest['depart_date']);
            self::assertSame(60.00, $cheapest['base']);
            self::assertSame(15.00, $cheapest['tax']);
        } finally {
            $this->forget('ZZQ', 'ZZR');
        }
    }

    public function testCheapestIgnoresDaysBeforeSince(): void
    {
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(-5), 1.00, 0.00);
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(10), 50.00, 10.00);

        try {
            $cheapest = $this->prices->cheapest('ZZQ', 'ZZR', self::CABIN, $this->since);

            self::assertNotNull($cheapest);
            self::assertSame(self::day(10), $cheapest['depart_date']);
        } finally {
            $this->forget('ZZQ', 'ZZR');
        }
    }

    public function testCheapestIsNullWithNoRows(): void
    {
        self::assertNull($this->prices->cheapest('ZZQ', 'ZZR', self::CABIN, $this->since));
    }

    /**
     * `$until`, for the homepage's Explore cards (C8, #157): "this month",
     * not whichever day in the whole cache turns out cheapest.
     */
    public function testCheapestIsBoundedByUntilWhenGiven(): void
    {
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(10), 50.00, 10.00);
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(40), 5.00, 1.00);

        try {
            $cheapest = $this->prices->cheapest('ZZQ', 'ZZR', self::CABIN, $this->since, self::day(30));

            self::assertNotNull($cheapest);
            self::assertSame(self::day(10), $cheapest['depart_date'], 'the cheaper day outside until should not win');
        } finally {
            $this->forget('ZZQ', 'ZZR');
        }
    }

    public function testCheapestWithNoUntilStillSeesEveryDay(): void
    {
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(10), 50.00, 10.00);
        $this->insertDayPrice('ZZQ', 'ZZR', self::day(40), 5.00, 1.00);

        try {
            $cheapest = $this->prices->cheapest('ZZQ', 'ZZR', self::CABIN, $this->since);

            self::assertNotNull($cheapest);
            self::assertSame(self::day(40), $cheapest['depart_date'], 'no until should not lose the cheaper, later day');
        } finally {
            $this->forget('ZZQ', 'ZZR');
        }
    }

    private function insertDayPrice(string $from, string $to, string $date, float $base, float $tax): void
    {
        $this->connection()->execute(
            'INSERT INTO route_day_price (from_code, to_code, cabin, depart_date, price_base, price_tax)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [$from, $to, self::CABIN->value, $date, $base, $tax],
        );
    }
}
