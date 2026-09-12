<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TripBuilder\Http\RateLimit;

final class RateLimitTest extends TestCase
{
    public function testEveryScopeAllowsSomethingAndNotEverything(): void
    {
        foreach (RateLimit::cases() as $scope) {
            self::assertGreaterThan(0, $scope->perHour(), $scope->value . ' allows nothing at all');
            self::assertLessThanOrEqual(
                100,
                $scope->perHour(),
                $scope->value . ' is high enough that it stops nothing. If that is on purpose, say so here.',
            );
        }
    }

    /**
     * The value is a database key, and the column is `varchar(16)`.
     *
     * A longer case would be truncated by a permissive MySQL and rejected by a
     * strict one, and the first way it shows up is two scopes sharing a row.
     */
    public function testEveryScopeFitsTheColumnItIsStoredIn(): void
    {
        foreach (RateLimit::cases() as $scope) {
            self::assertLessThanOrEqual(16, strlen($scope->value), $scope->name . ' will not fit `scope`');
        }
    }

    /**
     * The refusal names no number and no unit of time.
     *
     * A message that says "10 per hour" tells somebody writing a script exactly
     * what to sleep for. Vagueness costs the honest visitor nothing: they were
     * not counting either way.
     */
    public function testTheRefusalCannotBeTimedAgainst(): void
    {
        foreach (RateLimit::cases() as $scope) {
            self::assertDoesNotMatchRegularExpression('/\d/', $scope->refusal());
            self::assertDoesNotMatchRegularExpression(
                '/\b(hour|minute|second|day)s?\b/i',
                $scope->refusal(),
            );
        }
    }
}
