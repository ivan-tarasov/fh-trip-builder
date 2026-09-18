<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Controllers;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TripBuilder\Controllers\AdminController;
use TripBuilder\Noah\Alerts\Check as AlertsCheck;
use TripBuilder\Noah\Currency\Rates as CurrencyRates;
use TripBuilder\Noah\Flights\Generate as GenerateFlights;

/**
 * The schedule history page's own duration column, and the job editor's
 * own command help -- both pure, neither needing a database.
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

    public function testDescriptionComesFromTheRealCommandNotACopyOfIt(): void
    {
        $help = AdminController::scheduledCommandHelp();

        self::assertSame(new CurrencyRates()->getDescription(), $help[CurrencyRates::NAME]['description']);
    }

    public function testAnArgumentCarriesItsOwnNameAndDescription(): void
    {
        $help = AdminController::scheduledCommandHelp();
        $arguments = $help[CurrencyRates::NAME]['arguments'];

        self::assertCount(1, $arguments);
        self::assertSame('span', $arguments[0]['name']);
        self::assertFalse($arguments[0]['required']);
        self::assertSame(CurrencyRates::ARGUMENTS['span'], $arguments[0]['description']);
    }

    public function testOptionsCarryWhetherEachOneTakesAValue(): void
    {
        $help = AdminController::scheduledCommandHelp();
        $byFlag = array_combine(
            array_column($help[GenerateFlights::NAME]['options'], 'flag'),
            $help[GenerateFlights::NAME]['options'],
        );

        self::assertTrue($byFlag['--day']['takesValue'], '--day takes a date or a day count');
        self::assertFalse($byFlag['--level']['takesValue'], '--level is a bare flag');
        self::assertSame(GenerateFlights::OPTIONS['day'], $byFlag['--day']['description']);
        self::assertSame(GenerateFlights::OPTIONS['level'], $byFlag['--level']['description']);
    }

    public function testACommandWithNoConfigureHasBothListsEmpty(): void
    {
        $help = AdminController::scheduledCommandHelp();

        self::assertSame([], $help[AlertsCheck::NAME]['arguments']);
        self::assertSame([], $help[AlertsCheck::NAME]['options']);
    }
}
