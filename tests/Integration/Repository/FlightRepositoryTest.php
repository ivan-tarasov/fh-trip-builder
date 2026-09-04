<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class FlightRepositoryTest extends IntegrationTestCase
{
    private const DEPART_DATE = '2026-09-15';
    private const RETURN_DATE = '2026-09-22';

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
        $ids = array_values(array_filter([$this->outboundA, $this->outboundB, $this->return]));

        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $this->connection()->execute("DELETE FROM flights WHERE id IN ($placeholders)", $ids);
        }
    }

    public function testOnewaySearchReturnsRankedItineraries(): void
    {
        $result = $this->repository()->searchDirection('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10);

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
        $result = $this->repository()->searchDirection('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10);

        self::assertLessThanOrEqual(10, count($result['rows']));
    }

    public function testFindByIdReturnsLegOrNull(): void
    {
        $leg = $this->repository()->findById($this->outboundA);

        self::assertNotNull($leg);
        self::assertSame($this->outboundA, (int) $leg['id']);
        self::assertSame('YUL', $leg['dep_code']);
        self::assertSame('YYZ', $leg['arr_code']);

        // Delete a fixture leg, then confirm it is no longer found.
        $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$this->outboundB]);
        self::assertNull($this->repository()->findById($this->outboundB));
    }

    public function testLegsByIdsPreservesOrder(): void
    {
        $legs = $this->repository()->legsByIds([$this->return, $this->outboundA]);

        self::assertCount(2, $legs);
        self::assertSame($this->return, (int) $legs[0]['id']);
        self::assertSame($this->outboundA, (int) $legs[1]['id']);
    }

    public function testSearchDirectionCoversTheReturnLegToo(): void
    {
        // A round trip searches each direction on its own date; the return is
        // the same query with the endpoints and date swapped.
        $result = $this->repository()->searchDirection('YYZ', 'YUL', self::RETURN_DATE, SortMethod::Price, 0, 10);

        self::assertGreaterThanOrEqual(1, $result['total']);
        self::assertNotEmpty($result['rows']);

        $cheapest = $result['rows'][0];
        $this->assertValidItinerary($cheapest);
        self::assertSame('YYZ', $cheapest['legs'][0]['dep_code']);
        self::assertSame('YUL', end($cheapest['legs'])['arr_code']);
    }

    public function testItineraryByIdsRebuildsAChosenOutbound(): void
    {
        $itinerary = $this->repository()->itineraryByIds([$this->outboundA]);

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
        $ranked = $this->repository()->searchDirection('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10);
        $cheapest = $ranked['rows'][0];
        $ids = array_map(static fn(array $leg): int => (int) $leg['id'], $cheapest['legs']);

        $rebuilt = $this->repository()->itineraryByIds($ids);

        self::assertNotNull($rebuilt);
        self::assertSame($cheapest['duration'], $rebuilt['duration']);
    }

    public function testItineraryByIdsRejectsBrokenSelections(): void
    {
        // A leg that no longer exists.
        self::assertNull($this->repository()->itineraryByIds([$this->outboundA, 2000000099]));

        // Two legs that do not chain (both YUL->YYZ, so leg 2 doesn't start
        // where leg 1 landed) must not be accepted as an itinerary.
        self::assertNull($this->repository()->itineraryByIds([$this->outboundA, $this->outboundB]));
    }

    public function testSearchWithUnknownAirportShortCircuitsToEmpty(): void
    {
        $result = $this->repository()->searchDirection('ZZZ', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10);

        self::assertSame([], $result['rows']);
        self::assertSame(0, $result['total']);
        self::assertNull($result['cheapest']);
    }

    public function testSearchReportsTheCheapestOfEveryResult(): void
    {
        // `cheapest` anchors the "+$X vs cheapest" note on each card, so it must
        // be the lowest total across all results — not just the first page.
        $result = $this->repository()->searchDirection('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 0, 10);

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
        $result = $this->repository()->searchDirection('PAR', 'NYC', '2026-09-15', SortMethod::LayoverShort, 0, 10);

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
        $result = $this->repository()->searchDirection('PAR', 'NYC', '2026-09-15', SortMethod::Recommended, 0, 10);

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
        $highlights = $repository->searchDirection('PAR', 'NYC', '2026-09-15', SortMethod::Price, 0, 10)['highlights'];

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

    private function insertFlight(string $airline, string $from, string $departure, string $to, string $arrival): int
    {
        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$airline, 100, $from, $departure, $to, $arrival, 504, 75, 20.00, 3.00, 4.10],
        );
    }
}
