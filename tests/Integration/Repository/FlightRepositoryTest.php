<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class FlightRepositoryTest extends IntegrationTestCase
{
    // High ids that won't collide with seeded/generated data.
    private const OUTBOUND_A = 990001;
    private const OUTBOUND_B = 990002;
    private const RETURN = 990003;
    private const DEPART_DATE = '2026-09-15';
    private const RETURN_DATE = '2026-09-22';

    protected function setUp(): void
    {
        $this->cleanup();

        // Two outbound YUL->YYZ on the depart date, one return YYZ->YUL on the
        // return date — enough to exercise one-way search and the round-trip pair.
        $this->insertFlight(self::OUTBOUND_A, 'AC', 'YUL', self::DEPART_DATE . ' 06:00:00', 'YYZ', self::DEPART_DATE . ' 07:15:00');
        $this->insertFlight(self::OUTBOUND_B, 'WS', 'YUL', self::DEPART_DATE . ' 12:00:00', 'YYZ', self::DEPART_DATE . ' 13:20:00');
        $this->insertFlight(self::RETURN, 'AC', 'YYZ', self::RETURN_DATE . ' 20:00:00', 'YUL', self::RETURN_DATE . ' 21:20:00');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testOnewaySearchReturnsOutboundLegAliasesAndTotal(): void
    {
        $result = $this->repository()->onewaySearch('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 1);

        self::assertGreaterThanOrEqual(2, $result['total']);
        self::assertNotEmpty($result['rows']);

        $row = $result['rows'][0];
        foreach (['out_id', 'out_carrier', 'out_dep_code', 'out_arr_code', 'out_price_base', 'out_rating'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertArrayNotHasKey('in_id', $row);
    }

    public function testOnewaySearchPaginatesToPerPage(): void
    {
        $result = $this->repository()->onewaySearch('YUL', 'YYZ', self::DEPART_DATE, SortMethod::Price, 1);

        self::assertLessThanOrEqual(10, count($result['rows']));
    }

    public function testFindByIdReturnsRowOrNull(): void
    {
        $found = $this->repository()->findById(self::OUTBOUND_A);

        self::assertNotNull($found);
        self::assertSame(self::OUTBOUND_A, (int) $found['out_id']);
        self::assertSame('YUL', $found['out_dep_code']);

        self::assertNull($this->repository()->findById(999999999));
    }

    public function testRoundtripSearchReturnsBothLegAliases(): void
    {
        $result = $this->repository()->roundtripSearch('YUL', 'YYZ', self::DEPART_DATE, self::RETURN_DATE, SortMethod::Price, 1);

        self::assertGreaterThanOrEqual(1, $result['total']);
        self::assertNotEmpty($result['rows']);

        $row = $result['rows'][0];
        foreach (['out_id', 'out_price_base', 'in_id', 'in_price_base', 'in_arr_code'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
    }

    private function repository(): FlightRepository
    {
        return new FlightRepository($this->connection());
    }

    private function insertFlight(int $id, string $airline, string $from, string $departure, string $to, string $arrival): void
    {
        $this->connection()->execute(
            'INSERT INTO flights (id, airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $airline, 100, $from, $departure, $to, $arrival, 504, 75, 120.00, 18.00, 4.10],
        );
    }

    private function cleanup(): void
    {
        $this->connection()->execute(
            'DELETE FROM flights WHERE id IN (?, ?, ?)',
            [self::OUTBOUND_A, self::OUTBOUND_B, self::RETURN],
        );
    }
}
