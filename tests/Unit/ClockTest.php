<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Clock;

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
