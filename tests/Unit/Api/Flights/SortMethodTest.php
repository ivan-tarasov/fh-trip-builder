<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Api\Flights;

use PHPUnit\Framework\TestCase;
use TripBuilder\Api\Flights\SortMethod;

final class SortMethodTest extends TestCase
{
    public function testFromRequestResolvesKnownValues(): void
    {
        self::assertSame(SortMethod::Duration, SortMethod::fromRequest('duration'));
        self::assertSame(SortMethod::Rating, SortMethod::fromRequest('rating'));
    }

    public function testFromRequestFallsBackToPrice(): void
    {
        self::assertSame(SortMethod::Price, SortMethod::fromRequest('bogus'));
        self::assertSame(SortMethod::Price, SortMethod::fromRequest(''));
    }

    public function testOnewayExpressions(): void
    {
        self::assertSame('(out_price_base + out_price_tax)', SortMethod::Price->onewayOrderBy());
        self::assertSame('out_duration', SortMethod::Duration->onewayOrderBy());
        self::assertSame('out_dep_datetime', SortMethod::Depart->onewayOrderBy());
        self::assertSame('out_arr_datetime', SortMethod::Arrive->onewayOrderBy());
        self::assertSame('out_rating', SortMethod::Rating->onewayOrderBy());
    }

    public function testRoundtripExpressionsFallBackToPriceForLegOnlySorts(): void
    {
        $price = '(out_price_base + out_price_tax + in_price_base + in_price_tax)';

        self::assertSame($price, SortMethod::Price->roundtripOrderBy());
        self::assertSame('(out_duration + in_duration)', SortMethod::Duration->roundtripOrderBy());
        self::assertSame('(out_rating + in_rating)', SortMethod::Rating->roundtripOrderBy());
        // depart/arrive have no round-trip expression → price fallback (legacy parity)
        self::assertSame($price, SortMethod::Depart->roundtripOrderBy());
        self::assertSame($price, SortMethod::Arrive->roundtripOrderBy());
    }
}
