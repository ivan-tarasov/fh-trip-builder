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

    public function testFromRequestFallsBackToTheDefaultSort(): void
    {
        self::assertSame(SortMethod::Recommended, SortMethod::fromRequest('bogus'));
        self::assertSame(SortMethod::Recommended, SortMethod::fromRequest(''));
    }

    public function testCandidateOrderByLeadsWithItsOwnKey(): void
    {
        self::assertStringStartsWith('(price_base + price_tax) ASC', SortMethod::Price->candidateOrderBy());
        self::assertStringStartsWith('duration ASC', SortMethod::Duration->candidateOrderBy());
        self::assertStringStartsWith('depart_time ASC', SortMethod::Depart->candidateOrderBy());
        self::assertStringStartsWith('arrive_time ASC', SortMethod::Arrive->candidateOrderBy());
        // Rating sorts highest-first.
        self::assertStringStartsWith('rating DESC', SortMethod::Rating->candidateOrderBy());
        self::assertStringStartsWith('layover_minutes ASC', SortMethod::LayoverShort->candidateOrderBy());
    }

    public function testEverySortEndsWithTheSameTieBreak(): void
    {
        // Ties are common — layover_minutes is 0 for every direct flight — and
        // without a deterministic tail the database picks the winner, so the
        // same search can order equal rows differently from page to page.
        foreach (SortMethod::cases() as $sort) {
            self::assertStringEndsWith(
                ', (price_base + price_tax) ASC, seg1 ASC',
                $sort->candidateOrderBy(),
                $sort->value . ' has no tie-break',
            );
        }
    }

    public function testRecommendedStillSurvivesTheTieBreakTail(): void
    {
        // It is ranked in PHP afterwards, but the SQL still has to cut a stable
        // set of candidates for it to rank.
        self::assertStringStartsWith('(price_base + price_tax) ASC', SortMethod::Recommended->candidateOrderBy());
    }

    public function testOnlyRecommendedNeedsTheWholeResultSet(): void
    {
        // Everything else is expressible as an ORDER BY, so it can be settled
        // in SQL before the page is cut.
        foreach (SortMethod::cases() as $sort) {
            self::assertSame($sort === SortMethod::Recommended, $sort->ranksAcrossResults());
        }
    }

    public function testEveryCaseHasACandidateOrderBy(): void
    {
        // Each direction of a trip is ranked by these columns, so every sort
        // option must map to a valid ORDER BY over the candidate aggregates.
        foreach (SortMethod::cases() as $sort) {
            self::assertMatchesRegularExpression('/^[^;]+ (ASC|DESC)$/', $sort->candidateOrderBy());
            self::assertStringNotContainsString(';', $sort->candidateOrderBy());
        }
    }
}
