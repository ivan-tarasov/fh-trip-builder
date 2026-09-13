<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah\Flights;

use PHPUnit\Framework\TestCase;
use TripBuilder\Noah\Flights\DayPlan;

/**
 * Which day a nightly run fills.
 *
 * Pure arithmetic over a handful of days, which is the whole reason it is a
 * class rather than a few lines inside the generator: the interesting cases
 * are an empty day at the edge of the window and a night that was missed, and
 * neither wants half a million rows to ask about.
 */
final class DayPlanTest extends TestCase
{
    /** @return list<string> */
    private static function days(int $count): array
    {
        return DayPlan::window('2026-01-01', [1, $count]);
    }

    public function testAnEmptyBudgetPlansNothing(): void
    {
        self::assertSame([], DayPlan::level(self::days(3), [], 0));
        self::assertSame([], DayPlan::level([], ['2026-01-02' => 0], 100));
    }

    /**
     * The night after a normal night: one day is empty and the rest are level,
     * so everything goes to the empty one.
     *
     * This is the case that matters. Spread evenly instead — which is what
     * `flights:add N` does on its own — and the new day gets N/90 and stays
     * thin for three months (E24.1, #191).
     */
    public function testTheDayThatJustEnteredTheWindowGetsTheLot(): void
    {
        $days = self::days(4);
        $have = [$days[0] => 100, $days[1] => 100, $days[2] => 100];

        self::assertSame([$days[3] => 60], DayPlan::level($days, $have, 60));
    }

    /** And it is raised no further than its neighbours before they share. */
    public function testOnceItIsLevelTheRestIsShared(): void
    {
        $days = self::days(3);
        $have = [$days[0] => 100, $days[1] => 100, $days[2] => 0];

        // 100 to catch up, then 30 across three days.
        $plan = DayPlan::level($days, $have, 130);

        self::assertSame(130, array_sum($plan));
        self::assertSame([110, 110, 110], [
            $have[$days[0]] + ($plan[$days[0]] ?? 0),
            $have[$days[1]] + ($plan[$days[1]] ?? 0),
            $have[$days[2]] + ($plan[$days[2]] ?? 0),
        ]);
    }

    /**
     * A missed night, which is the reason this levels rather than naming a day.
     *
     * Two days are empty instead of one, and nothing had to tell it so.
     */
    public function testAMissedNightIsFoundWithoutBeingTold(): void
    {
        $days = self::days(5);
        $have = [$days[0] => 500, $days[1] => 500, $days[2] => 500];

        $plan = DayPlan::level($days, $have, 400);

        self::assertSame([$days[3] => 200, $days[4] => 200], $plan);
    }

    /**
     * A budget too small to level anything still goes to the thinnest, and is
     * spent exactly.
     */
    public function testASmallBudgetIsSpentOnTheThinnestAndSpentInFull(): void
    {
        $days = self::days(3);
        $have = [$days[0] => 10, $days[1] => 0, $days[2] => 5];

        $plan = DayPlan::level($days, $have, 3);

        self::assertSame(3, array_sum($plan));
        self::assertSame([$days[1] => 3], $plan, 'the emptiest day is still the emptiest after three');
    }

    /** Whatever the shape, the caller gets the number it asked for. */
    public function testTheBudgetIsAlwaysSpentInFull(): void
    {
        $days = self::days(7);
        $have = [$days[0] => 3, $days[2] => 11, $days[4] => 1, $days[6] => 40];

        foreach ([1, 7, 13, 100, 1001] as $budget) {
            self::assertSame(
                $budget,
                array_sum(DayPlan::level($days, $have, $budget)),
                'planning ' . $budget . ' should place ' . $budget,
            );
        }
    }

    /** Equal days are raised in calendar order, so two runs plan alike. */
    public function testTiesAreBrokenByTheCalendar(): void
    {
        $days = self::days(3);

        self::assertSame(
            [$days[0] => 1, $days[1] => 1, $days[2] => 1],
            DayPlan::level($days, [], 3),
        );
        self::assertSame([$days[0] => 1], DayPlan::level($days, [], 1));
    }

    public function testTheWindowIsEveryDayInclusive(): void
    {
        self::assertSame(
            ['2026-01-02', '2026-01-03', '2026-01-04'],
            DayPlan::window('2026-01-01', [1, 3]),
        );
        self::assertSame(['2026-01-01'], DayPlan::window('2026-01-01', [0, 0]));
    }

    /** A window that crosses a month, and a leap day, because dates do that. */
    public function testTheWindowCrossesMonthsAndLeapDays(): void
    {
        self::assertSame(
            ['2028-02-28', '2028-02-29', '2028-03-01'],
            DayPlan::window('2028-02-27', [1, 3]),
        );
    }
}
