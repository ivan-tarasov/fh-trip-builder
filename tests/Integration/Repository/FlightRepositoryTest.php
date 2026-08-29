<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

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
        $result = $this->repository()->onewaySearch('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 1);

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
        $result = $this->repository()->onewaySearch('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 1);

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

    public function testRoundtripSearchPairsBothDirections(): void
    {
        $result = $this->repository()->roundtripSearch('YUL', 'YYZ', self::DEPART_DATE, self::RETURN_DATE, SortMethod::Price, 1);

        self::assertGreaterThanOrEqual(1, $result['total']);
        self::assertNotEmpty($result['rows']);

        $row = $result['rows'][0];
        self::assertArrayHasKey('outbound', $row);
        self::assertArrayHasKey('returning', $row);

        $this->assertValidItinerary($row['outbound']);
        $this->assertValidItinerary($row['returning']);

        // Outbound goes YUL -> ... -> YYZ; the return reverses it.
        self::assertSame('YUL', $row['outbound']['legs'][0]['dep_code']);
        self::assertSame('YYZ', end($row['outbound']['legs'])['arr_code']);
        self::assertSame('YYZ', $row['returning']['legs'][0]['dep_code']);
        self::assertSame('YUL', end($row['returning']['legs'])['arr_code']);

        self::assertEqualsWithDelta(
            $row['outbound']['price_base'] + $row['returning']['price_base'],
            $row['price_base'],
            0.01,
        );
    }

    public function testSearchWithUnknownAirportShortCircuitsToEmpty(): void
    {
        self::assertSame(
            ['rows' => [], 'total' => 0],
            $this->repository()->onewaySearch('ZZZ', 'YYZ', self::DEPART_DATE, SortMethod::Price, 1),
        );
        self::assertSame(
            ['rows' => [], 'total' => 0],
            $this->repository()->roundtripSearch('ZZZ', 'YYZ', self::DEPART_DATE, self::RETURN_DATE, SortMethod::Price, 1),
        );
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
