<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class FlightRepositoryTest extends IntegrationTestCase
{
    private const DEPART_DATE = '2026-09-15';
    private const RETURN_DATE = '2026-09-22';

    // The fixtures are priced far below the generated network so they rank
    // first; the cabin test reads them back, so they are named.
    private const int FIXTURE_KM = 504;
    private const float FIXTURE_BASE = 20.00;
    private const float FIXTURE_TAX = 3.00;

    private const LEG_KEYS = [
        'id', 'carrier', 'carrier_name', 'number',
        'dep_code', 'dep_name', 'dep_country', 'dep_city', 'dep_datetime',
        'arr_code', 'arr_name', 'arr_country', 'arr_city', 'arr_datetime',
        'aircraft_code', 'aircraft_name',
        'distance', 'duration', 'price_base', 'price_tax', 'rating',
    ];

    // Auto-increment assigns these; capturing them avoids poisoning the
    // sequence with explicit ids (which would collide with generated flights).
    private ?int $outboundA = null;
    private ?int $outboundB = null;
    private ?int $return = null;

    /**
     * Fixtures a single test inserts for itself, dropped with the rest.
     *
     * @var list<int>
     */
    private array $extraIds = [];

    protected function setUp(): void
    {
        // Two cheap direct YUL->YYZ on the depart date, one cheap return
        // YYZ->YUL on the return date — priced low so they rank ahead of the
        // generated data and the assertions are deterministic.
        $this->outboundA = $this->insertFlight('AC', 'YUL', self::DEPART_DATE . ' 06:00:00', 'YYZ', self::DEPART_DATE . ' 07:15:00');
        $this->outboundB = $this->insertFlight('WS', 'YUL', self::DEPART_DATE . ' 12:00:00', 'YYZ', self::DEPART_DATE . ' 13:20:00');
        $this->return = $this->insertFlight('AC', 'YYZ', self::RETURN_DATE . ' 20:00:00', 'YUL', self::RETURN_DATE . ' 21:20:00');
    }

    protected function tearDown(): void
    {
        $ids = array_values(array_filter(
            [$this->outboundA, $this->outboundB, $this->return, ...$this->extraIds],
        ));

        $this->extraIds = [];

        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $this->connection()->execute("DELETE FROM flights WHERE id IN ($placeholders)", $ids);
        }
    }

    public function testOnewaySearchReturnsRankedItineraries(): void
    {
        $result = $this->repository()->searchDirection('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10, CabinClass::Economy);

        self::assertGreaterThanOrEqual(2, $result['total']);
        self::assertNotEmpty($result['rows']);

        foreach ($result['rows'] as $itin) {
            $this->assertValidItinerary($itin);
        }

        // The cheapest option is one of our low-priced direct YUL->YYZ flights.
        $cheapest = $result['rows'][0];
        self::assertSame(0, $cheapest['stops']);
        self::assertCount(1, $cheapest['legs']);
        self::assertSame('YUL', $cheapest['legs'][0]['dep_code']);
        self::assertSame('YYZ', $cheapest['legs'][0]['arr_code']);
    }

    public function testOnewaySearchPaginatesToPerPage(): void
    {
        $result = $this->repository()->searchDirection('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10, CabinClass::Economy);

        self::assertLessThanOrEqual(10, count($result['rows']));
    }

    public function testFindByIdReturnsLegOrNull(): void
    {
        $leg = $this->repository()->findById($this->outboundA, CabinClass::Economy);

        self::assertNotNull($leg);
        self::assertSame($this->outboundA, (int) $leg['id']);
        self::assertSame('YUL', $leg['dep_code']);
        self::assertSame('YYZ', $leg['arr_code']);

        // Delete a fixture leg, then confirm it is no longer found.
        $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$this->outboundB]);
        self::assertNull($this->repository()->findById($this->outboundB, CabinClass::Economy));
    }

    public function testLegsByIdsPreservesOrder(): void
    {
        $legs = $this->repository()->legsByIds([$this->return, $this->outboundA], CabinClass::Economy);

        self::assertCount(2, $legs);
        self::assertSame($this->return, (int) $legs[0]['id']);
        self::assertSame($this->outboundA, (int) $legs[1]['id']);
    }

    public function testSearchDirectionCoversTheReturnLegToo(): void
    {
        // A round trip searches each direction on its own date; the return is
        // the same query with the endpoints and date swapped.
        $result = $this->repository()->searchDirection('YYZ', 'YUL', self::RETURN_DATE, SortMethod::Price, 0, 10, CabinClass::Economy);

        self::assertGreaterThanOrEqual(1, $result['total']);
        self::assertNotEmpty($result['rows']);

        $cheapest = $result['rows'][0];
        $this->assertValidItinerary($cheapest);
        self::assertSame('YYZ', $cheapest['legs'][0]['dep_code']);
        self::assertSame('YUL', end($cheapest['legs'])['arr_code']);
    }

    public function testItineraryByIdsRebuildsAChosenOutbound(): void
    {
        $itinerary = $this->repository()->itineraryByIds([$this->outboundA], CabinClass::Economy);

        self::assertNotNull($itinerary);
        $this->assertValidItinerary($itinerary);
        self::assertSame(0, $itinerary['stops']);
        self::assertSame('YUL', $itinerary['legs'][0]['dep_code']);
    }

    public function testItineraryByIdsDurationMatchesTheSearch(): void
    {
        // Leg stamps are local to their own airports, so a rebuilt itinerary
        // must total flying + waiting time, not subtract arrival from departure
        // (which inflates any trip crossing timezones).
        $ranked = $this->repository()->searchDirection('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10, CabinClass::Economy);
        $cheapest = $ranked['rows'][0];
        $ids = array_map(static fn(array $leg): int => (int) $leg['id'], $cheapest['legs']);

        $rebuilt = $this->repository()->itineraryByIds($ids, CabinClass::Economy);

        self::assertNotNull($rebuilt);
        self::assertSame($cheapest['duration'], $rebuilt['duration']);
    }

    public function testCabinPricesAndFiltersTheSameItinerary(): void
    {
        // Sells economy and business, which is the normal short-haul shape --
        // a narrowbody with a curtain and no premium economy behind it.
        $id = $this->insertFlight(
            'AC',
            'YUL',
            self::DEPART_DATE . ' 09:00:00',
            'YYZ',
            self::DEPART_DATE . ' 10:15:00',
            CabinClass::Economy->bit() | CabinClass::Business->bit(),
        );

        // Left behind, this fixture has no aircraft and would break the
        // network invariant FlightAircraftTest asserts.
        $this->extraIds[] = $id;

        $economy = $this->repository()->itineraryByIds([$id], CabinClass::Economy);
        $business = $this->repository()->itineraryByIds([$id], CabinClass::Business);

        self::assertNotNull($economy);
        self::assertNotNull($business);

        // Economy is the anchor: its multiplier is 1.0, so the stored fare is
        // what it is priced at.
        self::assertSame(self::FIXTURE_BASE, (float) $economy['price_base']);
        self::assertSame(self::FIXTURE_TAX, (float) $economy['price_tax']);

        // Business is the same fare times the cabin's multiplier for this
        // distance, rounded per leg the way the search rounds it.
        $multiplier = CabinClass::Business->priceMultiplier(self::FIXTURE_KM);

        self::assertSame(round(self::FIXTURE_BASE * $multiplier, 2), (float) $business['price_base']);
        self::assertSame(round(self::FIXTURE_TAX * $multiplier, 2), (float) $business['price_tax']);
        self::assertGreaterThan((float) $economy['price_base'], (float) $business['price_base']);

        // Cabins it does not sell resolve to nothing rather than to a fare for
        // a seat that is not on board.
        self::assertNull($this->repository()->itineraryByIds([$id], CabinClass::PremiumEconomy));
        self::assertNull($this->repository()->itineraryByIds([$id], CabinClass::First));
    }

    public function testConnectionsCannotWanderFurtherThanTheDetourCap(): void
    {
        // YUL to YYZ is 504 km apart, so the cap falls back to its floor of
        // 2,000 km. Two connections are planted between the same pair of
        // cities: one that stays local and one that crosses the Atlantic twice.
        $nearOut = $this->insertFlight('AC', 'YUL', self::DEPART_DATE . ' 07:00:00', 'EWR', self::DEPART_DATE . ' 08:30:00', 1, 531);
        $nearIn = $this->insertFlight('AC', 'EWR', self::DEPART_DATE . ' 10:00:00', 'YYZ', self::DEPART_DATE . ' 11:15:00', 1, 558);
        $farOut = $this->insertFlight('AC', 'YUL', self::DEPART_DATE . ' 06:00:00', 'LHR', self::DEPART_DATE . ' 18:00:00', 1, 5216);
        $farIn = $this->insertFlight('AC', 'LHR', self::DEPART_DATE . ' 20:00:00', 'YYZ', self::DEPART_DATE . ' 23:00:00', 1, 5700);

        $this->extraIds = [...$this->extraIds, $nearOut, $nearIn, $farOut, $farIn];

        $result = $this->repository()->searchDirection(
            'YUL',
            'YYZ',
            self::DEPART_DATE,
            SortMethod::Duration,
            0,
            210,
            CabinClass::Economy,
            new FlightFilters(stops: [1]),
        );

        $routings = [];

        foreach ($result['rows'] as $row) {
            $flown = 0;

            foreach ($row['legs'] as $leg) {
                $flown += (int) $leg['distance'];
            }

            $routings[] = ['stop' => $row['legs'][0]['arr_code'], 'flown' => $flown];
        }

        $stops = array_column($routings, 'stop');

        // Both connect in time, and before the cap existed both were offered.
        self::assertContains('EWR', $stops, 'A local connection should still be offered');
        self::assertNotContains('LHR', $stops, 'A connection via London is 10,916 km for a 504 km trip');

        // Nothing else slipped past either.
        foreach ($routings as $routing) {
            self::assertLessThanOrEqual(
                2000,
                $routing['flown'],
                sprintf('Itinerary via %s flies %d km', $routing['stop'], $routing['flown']),
            );
        }
    }

    public function testItineraryByIdsRejectsBrokenSelections(): void
    {
        // A leg that no longer exists.
        self::assertNull($this->repository()->itineraryByIds([$this->outboundA, 2000000099], CabinClass::Economy));

        // Two legs that do not chain (both YUL->YYZ, so leg 2 doesn't start
        // where leg 1 landed) must not be accepted as an itinerary.
        self::assertNull($this->repository()->itineraryByIds([$this->outboundA, $this->outboundB], CabinClass::Economy));
    }

    public function testSearchWithUnknownAirportShortCircuitsToEmpty(): void
    {
        $result = $this->repository()->searchDirection('ZZZ', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10, CabinClass::Economy);

        self::assertSame([], $result['rows']);
        self::assertSame(0, $result['total']);
        self::assertNull($result['cheapest']);
    }

    public function testSearchReportsTheCheapestOfEveryResult(): void
    {
        // `cheapest` anchors the "+$X vs cheapest" note on each card, so it must
        // be the lowest total across all results — not just the first page.
        $result = $this->repository()->searchDirection('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10, CabinClass::Economy);

        self::assertNotNull($result['cheapest']);

        $pageTotals = array_map(
            static fn(array $itinerary): float => $itinerary['price_base'] + $itinerary['price_tax'],
            $result['rows'],
        );

        // Sorted by price, so the first row of page one is that cheapest total.
        self::assertEqualsWithDelta(min($pageTotals), $result['cheapest'], 0.01);

        // And nothing on the page can be cheaper than it.
        foreach ($pageTotals as $total) {
            self::assertGreaterThanOrEqual($result['cheapest'] - 0.01, $total);
        }
    }

    public function testShortLayoverSortPutsTheLeastWaitingFirst(): void
    {
        $result = $this->repository()->searchDirection('PAR', 'NYC', '2026-09-15', SortMethod::LayoverShort, 0, 10, CabinClass::Economy);

        if ($result['total'] === 0) {
            self::markTestSkipped('No generated flights on this route.');
        }

        $waits = array_map(static function (array $itinerary): int {
            $flying = array_sum(array_map(static fn(array $leg): int => (int) $leg['duration'], $itinerary['legs']));

            return $itinerary['duration'] - $flying;
        }, $result['rows']);

        $ordered = $waits;
        sort($ordered);

        self::assertSame($ordered, $waits);

        // A direct itinerary waits nowhere, so where the route has one the sort
        // has to lead with it. Not every route on every date does — the top of
        // the list is then the shortest connection, which the ordering above
        // already covers. Asserting the zero outright reads as a claim about
        // the sort but is really a claim about the data.
        $directs = $this->repository()->searchDirection(
            'PAR',
            'NYC',
            '2026-09-15',
            SortMethod::Price,
            0,
            1,
            CabinClass::Economy,
            new FlightFilters(stops: [0]),
        )['total'];

        if ($directs > 0) {
            self::assertSame(0, $waits[0]);
        }
    }

    public function testRecommendedSortLeadsWithTheBestValueItinerary(): void
    {
        // The Recommended sort and the "Best value" badge score itineraries the
        // same way, so the badge must land on the first row. If these ever
        // disagree the app is telling the visitor two different things about
        // which flight is the good one.
        $result = $this->repository()->searchDirection('PAR', 'NYC', '2026-09-15', SortMethod::Recommended, 0, 10, CabinClass::Economy);

        if ($result['total'] < 3) {
            self::markTestSkipped('Badges need at least a few options to mean anything.');
        }

        self::assertContains('value', $result['rows'][0]['badges']);
    }

    public function testEverySortTabPromisesWhatThatSortActuallyReturns(): void
    {
        // The tabs above the results advertise a price and a travel time for
        // each sort. If the advertised pair is not the pair you get on choosing
        // it, the control is lying about the trade it offers.
        $repository = $this->repository();
        $highlights = $repository->searchDirection('PAR', 'NYC', '2026-09-15', SortMethod::Price, 0, 10, CabinClass::Economy)['highlights'];

        if ($highlights === []) {
            self::markTestSkipped('No generated flights on this route.');
        }

        foreach ($highlights as $sort => $promised) {
            $first = $repository->searchDirection(
                'PAR',
                'NYC',
                '2026-09-15',
                SortMethod::fromRequest((string) $sort),
                0,
                10,
                CabinClass::Economy,
            )['rows'][0] ?? null;

            self::assertNotNull($first, sprintf('%s returned nothing', $sort));
            self::assertEqualsWithDelta(
                $promised['price'],
                $first['price_base'] + $first['price_tax'],
                0.01,
                sprintf('%s advertises a price it does not deliver', $sort),
            );
            self::assertSame(
                $promised['duration'],
                $first['duration'],
                sprintf('%s advertises a duration it does not deliver', $sort),
            );
        }
    }

    /**
     * @param array<string, mixed> $itin
     */
    private function assertValidItinerary(array $itin): void
    {
        self::assertNotEmpty($itin['legs']);
        self::assertCount($itin['stops'] + 1, $itin['legs']);
        self::assertSame(self::LEG_KEYS, array_keys($itin['legs'][0]));

        $priceBase = 0.0;

        for ($i = 0; $i < count($itin['legs']); $i++) {
            $priceBase += (float) $itin['legs'][$i]['price_base'];

            if ($i > 0) {
                // Consecutive legs connect at the same airport.
                self::assertSame($itin['legs'][$i - 1]['arr_code'], $itin['legs'][$i]['dep_code']);
            }
        }

        self::assertEqualsWithDelta($priceBase, $itin['price_base'], 0.5);
    }

    private function repository(): FlightRepository
    {
        return new FlightRepository($this->connection());
    }

    private function insertFlight(
        string $airline,
        string $from,
        string $departure,
        string $to,
        string $arrival,
        int $cabins = 1,
        int $distance = self::FIXTURE_KM,
    ): int {
        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, cabins, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$airline, 100, $from, $departure, $to, $arrival, $distance, 75, $cabins, self::FIXTURE_BASE, self::FIXTURE_TAX, 4.10],
        );
    }
}
