<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\Party;

final class FlightSearchQueryTest extends TestCase
{
    public function testHoldsItsValues(): void
    {
        $query = new FlightSearchQuery(
            offset: 10,
            limit: 20,
            sort: 'price',
            from: 'YUL',
            to: 'YYZ',
            departDate: '2026-09-15',
            returnDate: '2026-09-22',
            party: new Party(adults: 2, children: 1),
        );

        self::assertSame(10, $query->offset);
        self::assertSame(20, $query->limit);
        self::assertSame('price', $query->sort);
        self::assertSame('YUL', $query->from);
        self::assertSame('YYZ', $query->to);
        self::assertSame('2026-09-15', $query->departDate);
        self::assertSame('2026-09-22', $query->returnDate);
        self::assertSame(2, $query->party->adults);
        self::assertSame(1, $query->party->children);
    }

    public function testAllPropertiesAreReadonly(): void
    {
        foreach (['offset', 'limit', 'sort', 'from', 'to', 'departDate', 'returnDate', 'party'] as $property) {
            self::assertTrue(
                (new ReflectionProperty(FlightSearchQuery::class, $property))->isReadOnly(),
                "$property should be readonly",
            );
        }
    }
}
