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
        self::assertSame(SortMethod::LayoverShort, SortMethod::fromRequest('layover_short'));
        self::assertSame(SortMethod::Recommended, SortMethod::fromRequest('recommended'));
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
        self::assertSame('layover_minutes ASC', SortMethod::LayoverShort->candidateOrderBy());
    }

    public function testOnlyRecommendedNeedsTheWholeResultSet(): void
    {
        // Everything else is expressible as an ORDER BY, so it can be settled
        // in SQL before the page is cut.
        foreach (SortMethod::cases() as $sort) {
            self::assertSame($sort === SortMethod::Recommended, $sort->ranksAcrossResults());
        }
    }

    public function testRecommendedStillOrdersTheCandidatesItKeeps(): void
    {
        // It is ranked afterwards, but the SQL still needs an order to decide
        // which candidates survive the cap — and cheapest-first is the right
        // set to keep.
        self::assertSame('(price_base + price_tax) ASC', SortMethod::Recommended->candidateOrderBy());
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
