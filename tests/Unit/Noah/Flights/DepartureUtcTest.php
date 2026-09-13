<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah\Flights;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Noah\Flights\LegBuilder;

/**
 * A local departure time converted to the instant it actually is.
 *
 * `flights.departure_time` is local at the departure airport -- 08:00 at YUL is
 * 08:00 there, which is what a ticket says. Ten queries compared it against
 * `NOW()` to decide whether a flight had left, which is a wall clock against an
 * instant: offsets run from -11 to +13, so the answer was out by up to thirteen
 * hours (E20, #180).
 */
final class DepartureUtcTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function departures(): array
    {
        return [
            // East of Greenwich is ahead, so the instant is earlier.
            ['2026-09-15 08:00:00', 'Asia/Tokyo', '2026-09-14 23:00:00'],
            ['2026-09-15 08:00:00', 'Pacific/Auckland', '2026-09-14 20:00:00'],
            // West is behind, so the instant is later.
            ['2026-09-15 08:00:00', 'America/Los_Angeles', '2026-09-15 15:00:00'],
            ['2026-09-15 08:00:00', 'America/Toronto', '2026-09-15 12:00:00'],
            // A zone that is not a whole number of hours. Hours would put this
            // three quarters of an hour out, which is the kind of wrong that
            // reads as right.
            ['2026-09-15 08:00:00', 'Asia/Kathmandu', '2026-09-15 02:15:00'],
            ['2026-09-15 08:00:00', 'Asia/Kolkata', '2026-09-15 02:30:00'],
            // UTC itself is the identity.
            ['2026-09-15 08:00:00', 'UTC', '2026-09-15 08:00:00'],
        ];
    }

    #[DataProvider('departures')]
    public function testALocalDepartureBecomesTheInstantItIs(string $local, string $zone, string $utc): void
    {
        self::assertSame($utc, LegBuilder::departureUtc($local, $zone));
    }

    /**
     * The zone is applied by name, so daylight saving is applied too.
     *
     * Montreal is -05:00 in January and -04:00 in July. A stored numeric offset
     * cannot know that; `airports.timezone` holds one number for the year, and
     * it is only used for the one-off backfill of rows that predate this.
     */
    public function testDaylightSavingIsHonouredBecauseTheZoneIsNamed(): void
    {
        self::assertSame(
            '2026-01-15 13:00:00',
            LegBuilder::departureUtc('2026-01-15 08:00:00', 'America/Toronto'),
            'January should be five hours behind UTC',
        );

        self::assertSame(
            '2026-07-15 12:00:00',
            LegBuilder::departureUtc('2026-07-15 08:00:00', 'America/Toronto'),
            'July should be four hours behind UTC',
        );
    }

    /**
     * Crossing midnight is the case that looks wrong and is right.
     */
    public function testAnEarlyDepartureInTheEastIsTheDayBeforeInUtc(): void
    {
        self::assertSame(
            '2026-09-14 15:30:00',
            LegBuilder::departureUtc('2026-09-15 00:30:00', 'Asia/Tokyo'),
        );
    }
}
