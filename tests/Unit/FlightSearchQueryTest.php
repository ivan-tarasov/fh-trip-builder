<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TripBuilder\Api\Flights\FlightSearchQuery;

final class FlightSearchQueryTest extends TestCase
{
    public function testHoldsItsValues(): void
    {
        $query = new FlightSearchQuery(
            currentPage: 2,
            sort: 'price',
            from: 'YUL',
            to: 'YYZ',
            departDate: '2026-09-15',
            returnDate: '2026-09-22',
            adultNum: 1,
            childNum: 0,
        );

        self::assertSame(2, $query->currentPage);
        self::assertSame('price', $query->sort);
        self::assertSame('YUL', $query->from);
        self::assertSame('YYZ', $query->to);
        self::assertSame('2026-09-15', $query->departDate);
        self::assertSame('2026-09-22', $query->returnDate);
        self::assertSame(1, $query->adultNum);
        self::assertSame(0, $query->childNum);
    }

    public function testAllPropertiesAreReadonly(): void
    {
        foreach (['currentPage', 'sort', 'from', 'to', 'departDate', 'returnDate', 'adultNum', 'childNum'] as $property) {
            self::assertTrue(
                (new ReflectionProperty(FlightSearchQuery::class, $property))->isReadOnly(),
                "$property should be readonly",
            );
        }
    }
}
