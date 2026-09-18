<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Controllers;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TripBuilder\Controllers\AdminController;

/**
 * The schedule history page's own duration column, pure and none of it
 * needing a database.
 */
final class AdminControllerTest extends TestCase
{
    public function testStillRunningHasNoDuration(): void
    {
        self::assertNull(AdminController::runDuration(new DateTimeImmutable('2026-09-18 03:00:00'), null));
    }

    public function testUnderAMinuteIsSpelledInSeconds(): void
    {
        self::assertSame(
            '12s',
            AdminController::runDuration(
                new DateTimeImmutable('2026-09-18 03:00:00'),
                new DateTimeImmutable('2026-09-18 03:00:12'),
            ),
        );
    }

    public function testAWholeMinuteDropsTheSeconds(): void
    {
        self::assertSame(
            '2m',
            AdminController::runDuration(
                new DateTimeImmutable('2026-09-18 03:00:00'),
                new DateTimeImmutable('2026-09-18 03:02:00'),
            ),
        );
    }

    public function testMinutesAndSecondsBothShowWhenNeitherIsZero(): void
    {
        self::assertSame(
            '2m 5s',
            AdminController::runDuration(
                new DateTimeImmutable('2026-09-18 03:00:00'),
                new DateTimeImmutable('2026-09-18 03:02:05'),
            ),
        );
    }

    public function testAnInstantRunIsZeroMilliseconds(): void
    {
        $at = new DateTimeImmutable('2026-09-18 03:00:00');

        self::assertSame('0ms', AdminController::runDuration($at, $at));
    }

    public function testUnderASecondIsSpelledInMilliseconds(): void
    {
        self::assertSame(
            '127ms',
            AdminController::runDuration(
                new DateTimeImmutable('2026-09-18 03:00:00.000'),
                new DateTimeImmutable('2026-09-18 03:00:00.127'),
            ),
        );
    }

    public function testAWholeSecondOfMillisecondsSwitchesToSeconds(): void
    {
        self::assertSame(
            '1s',
            AdminController::runDuration(
                new DateTimeImmutable('2026-09-18 03:00:00.000'),
                new DateTimeImmutable('2026-09-18 03:00:01.000'),
            ),
        );
    }

    public function testJustUnderASecondStaysInMilliseconds(): void
    {
        self::assertSame(
            '999ms',
            AdminController::runDuration(
                new DateTimeImmutable('2026-09-18 03:00:00.000'),
                new DateTimeImmutable('2026-09-18 03:00:00.999'),
            ),
        );
    }
}
