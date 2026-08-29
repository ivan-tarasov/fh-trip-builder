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

    public function testCandidateOrderByExpressions(): void
    {
        self::assertSame('(price_base + price_tax) ASC', SortMethod::Price->candidateOrderBy());
        self::assertSame('duration ASC', SortMethod::Duration->candidateOrderBy());
        self::assertSame('depart_time ASC', SortMethod::Depart->candidateOrderBy());
        self::assertSame('arrive_time ASC', SortMethod::Arrive->candidateOrderBy());
        // Rating sorts highest-first.
        self::assertSame('rating DESC', SortMethod::Rating->candidateOrderBy());
    }

    public function testPairSortKeyCombinesBothDirections(): void
    {
        $out = ['price_base' => 100.0, 'price_tax' => 20.0, 'duration' => 300, 'rating' => 4.0];
        $in = ['price_base' => 200.0, 'price_tax' => 30.0, 'duration' => 500, 'rating' => 3.0];

        self::assertSame(350.0, SortMethod::Price->pairSortKey($out, $in));
        self::assertSame(800.0, SortMethod::Duration->pairSortKey($out, $in));
        // Rating is negated so higher-rated pairs sort first (ascending key).
        self::assertSame(-3.5, SortMethod::Rating->pairSortKey($out, $in));
        // Depart/arrive are one-way-only sorts, so they fall back to price.
        self::assertSame(350.0, SortMethod::Depart->pairSortKey($out, $in));
        self::assertSame(350.0, SortMethod::Arrive->pairSortKey($out, $in));
    }
}
