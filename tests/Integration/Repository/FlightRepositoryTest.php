<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class FlightRepositoryTest extends IntegrationTestCase
{
    public function testOnewaySearchReturnsOutboundLegAliasesAndTotal(): void
    {
        $result = $this->repository()->onewaySearch('YUL', 'YYZ', '2026-09-15', SortMethod::Price, 1);

        self::assertArrayHasKey('rows', $result);
        self::assertArrayHasKey('total', $result);
        self::assertGreaterThan(0, $result['total']);
        self::assertNotEmpty($result['rows']);

        $row = $result['rows'][0];
        foreach (['out_id', 'out_carrier', 'out_dep_code', 'out_arr_code', 'out_price_base', 'out_rating'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        // one-way rows carry no return leg
        self::assertArrayNotHasKey('in_id', $row);
    }

    public function testOnewaySearchPaginatesToPerPage(): void
    {
        $result = $this->repository()->onewaySearch('YUL', 'YYZ', '2026-09-15', SortMethod::Price, 1);

        self::assertLessThanOrEqual(10, count($result['rows']));
    }

    public function testFindByIdReturnsRowOrNull(): void
    {
        $result = $this->repository()->onewaySearch('YUL', 'YYZ', '2026-09-15', SortMethod::Price, 1);
        $id = (int) $result['rows'][0]['out_id'];

        $found = $this->repository()->findById($id);
        self::assertNotNull($found);
        self::assertSame($id, (int) $found['out_id']);

        self::assertNull($this->repository()->findById(999999999));
    }

    public function testRoundtripSearchReturnsBothLegAliases(): void
    {
        $result = $this->repository()->roundtripSearch('YUL', 'YYZ', '2026-09-15', '2026-09-22', SortMethod::Price, 1);

        self::assertGreaterThan(0, $result['total']);
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
}
