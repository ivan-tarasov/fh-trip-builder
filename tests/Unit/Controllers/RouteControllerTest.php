<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use TripBuilder\Controllers\RouteController;

/**
 * "Best month to fly" (C7, #156): grouping every direct-fare day into its
 * month, the median of each, and which one to call out -- all of it pure and
 * none of it needing a database, the same reasoning
 * {@see \TripBuilder\Noah\Alerts\Check::qualifies()} already gives its own
 * test file.
 */
final class RouteControllerTest extends TestCase
{
    public function testMedianOfAnOddCountIsTheMiddleValue(): void
    {
        self::assertSame(20.0, RouteController::median([30.0, 10.0, 20.0]));
    }

    public function testMedianOfAnEvenCountAveragesTheMiddleTwo(): void
    {
        self::assertSame(25.0, RouteController::median([10.0, 20.0, 30.0, 40.0]));
    }

    public function testMedianOfOneValueIsThatValue(): void
    {
        self::assertSame(5.0, RouteController::median([5.0]));
    }

    public function testNoDaysAtAllMakesNoMonths(): void
    {
        self::assertSame([], RouteController::monthsFor([], 'YUL', 'PAR'));
    }

    /**
     * The whole shape at once: two months, each with its own median, the
     * cheaper one flagged, and both bars read against the pricier month.
     */
    public function testGroupsByMonthAndFlagsTheCheaperOne(): void
    {
        $months = RouteController::monthsFor([
            ['depart_date' => '2026-11-05', 'total' => 500.0],
            ['depart_date' => '2026-11-12', 'total' => 400.0],
            ['depart_date' => '2026-11-19', 'total' => 600.0],
            ['depart_date' => '2026-12-03', 'total' => 900.0],
            ['depart_date' => '2026-12-10', 'total' => 1100.0],
        ], 'YUL', 'PAR');

        self::assertCount(2, $months);

        [$november, $december] = $months;

        self::assertSame('2026-11', $november['month']);
        self::assertSame(500.0, $november['median']);
        self::assertSame(3, $november['days']);
        self::assertTrue($november['is_cheapest']);
        self::assertSame(100, $december['bar'], 'the pricier month bars at the full width');

        self::assertSame('2026-12', $december['month']);
        self::assertSame(1000.0, $december['median']);
        self::assertFalse($december['is_cheapest']);

        // November's own bar is the fraction of December's median it takes to
        // reach November's -- 500 / 1000, not 500 / 500.
        self::assertSame(50, $november['bar']);
    }

    public function testTheLinkPointsAtTheCheapestDayInTheMonthNotJustAny(): void
    {
        $months = RouteController::monthsFor([
            ['depart_date' => '2026-11-05', 'total' => 500.0],
            ['depart_date' => '2026-11-12', 'total' => 350.0],
            ['depart_date' => '2026-11-19', 'total' => 600.0],
        ], 'YUL', 'PAR');

        self::assertStringContainsString('1211', $months[0]['search'], 'the 12 Nov date the 350 fare was on');
    }

    public function testASingleSampleMonthIsItsOwnMedianAndStillBarsAtTheFull(): void
    {
        $months = RouteController::monthsFor([
            ['depart_date' => '2026-11-05', 'total' => 500.0],
        ], 'YUL', 'PAR');

        self::assertSame(500.0, $months[0]['median']);
        self::assertSame(1, $months[0]['days']);
        self::assertTrue($months[0]['is_cheapest']);
        self::assertSame(100, $months[0]['bar']);
    }
}
