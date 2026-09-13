<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TripBuilder\Clock;
use TripBuilder\Helper;

final class ClockTest extends TestCase
{
    public function testAgreementIsReportedAsOk(): void
    {
        self::assertSame('ok', Clock::describe(0));
    }

    /**
     * A second or two between two machines is two clocks ticking, not two
     * timezones. A timezone difference is at least a quarter of an hour.
     */
    public function testASecondOrTwoOfSkewIsNotADisagreement(): void
    {
        self::assertSame('ok', Clock::describe(3));
        self::assertSame('ok', Clock::describe(-30));
        self::assertSame('ok', Clock::describe(Clock::TOLERANCE_SECONDS));
    }

    public function testAWholeTimezoneIsReportedWithItsDirection(): void
    {
        self::assertSame('4h behind', Clock::describe(-14400));
        self::assertSame('4h ahead', Clock::describe(14400));
        self::assertSame('30m ahead', Clock::describe(1800));
    }

    /**
     * Both entry points state the timezone rather than inheriting it.
     *
     * The database is put on PHP's *offset* when a connection is built, and
     * that offset is read once. On a zone that observes DST it would go stale
     * an hour after a transition, inside any command that runs long enough to
     * span one. UTC has no transitions, so the offset is `+00:00` forever.
     *
     * Inherited from `php.ini`, this held by luck: nothing in the repository
     * said it, and a host upgrade would have shifted every timestamp the
     * application writes with nothing anywhere to say it had.
     */
    public function testBothEntryPointsPinTheTimezone(): void
    {
        foreach (['/public/index.php', '/noah'] as $entry) {
            self::assertStringContainsString(
                "date_default_timezone_set('UTC')",
                (string) file_get_contents(Helper::getRootDir() . $entry),
                $entry . ' inherits its timezone from the server',
            );
        }
    }

    /**
     * A zone with no transitions, so the offset the connection pins never goes
     * stale — which is the whole reason the offset may be read only once.
     */
    public function testTheChosenZoneHasNoDaylightSaving(): void
    {
        $zone = new DateTimeZone('UTC');
        $next = $zone->getTransitions(time(), time() + (10 * 365 * 24 * 3600));

        self::assertLessThanOrEqual(1, count($next), 'UTC gained a transition, which would be news');
    }

    /**
     * A database that cannot be asked is not a database that agrees.
     *
     * Reporting `ok` for a question that failed is the one answer a health
     * check must never give.
     */
    public function testADatabaseThatCannotBeAskedIsNotReportedAsAgreeing(): void
    {
        self::assertSame('unknown', Clock::describe(null));
        self::assertNotSame('ok', Clock::describe(null));
    }
}
