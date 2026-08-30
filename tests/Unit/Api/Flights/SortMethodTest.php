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

    public function testEveryCaseHasACandidateOrderBy(): void
    {
        // Each direction of a trip is ranked by these columns, so every sort
        // option must map to a valid ORDER BY over the candidate aggregates.
        foreach (SortMethod::cases() as $sort) {
            self::assertMatchesRegularExpression('/^[^;]+ (ASC|DESC)$/', $sort->candidateOrderBy());
        }
    }
}
