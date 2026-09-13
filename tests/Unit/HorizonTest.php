<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Horizon;

/**
 * How far ahead this site goes, and the fact that one number says so.
 *
 * The defect this replaces was a calendar that paged to September 2028 while
 * the flights stopped at day ninety, so a route with several flights a day
 * answered "No flights found" and nothing told the visitor the route was fine.
 * A longer window on its own would not have fixed that -- it would have moved
 * the same cliff to month twelve. What removes it is the calendar and the data
 * ending together, which one constant can guarantee and two cannot (E30, #215).
 */
final class HorizonTest extends TestCase
{
    public function testItIsAYearFromTheDayItIsAsked(): void
    {
        self::assertSame('2027-09-13', Horizon::last('2026-09-13'));
        self::assertSame('2027-01-01', Horizon::last('2026-01-01'));
    }

    /** Rolling, so nothing needs a yearly edit. */
    public function testItMovesWithTheCalendar(): void
    {
        self::assertNotSame(Horizon::last('2026-09-13'), Horizon::last('2026-09-14'));
        self::assertSame(
            date('Y-m-d', (int) strtotime('+' . Horizon::DAYS . ' days')),
            Horizon::last(),
        );
    }

    /** A leap year is 366 days, so the same offset lands on a different date. */
    public function testItCountsDaysRatherThanNamingNextYear(): void
    {
        // 2027-09-13 plus 365 days crosses 2028-02-29.
        self::assertSame('2028-09-12', Horizon::last('2027-09-13'));
    }

    public function testTheLastDayIsCoveredAndTheNextIsNot(): void
    {
        self::assertTrue(Horizon::covers('2027-09-13', '2026-09-13'), 'the horizon itself is inside it');
        self::assertFalse(Horizon::covers('2027-09-14', '2026-09-13'));
    }

    /**
     * The past is not refused here, and deliberately: a date behind us is read
     * faithfully and finds nothing, which is the honest answer and what the
     * query-string form has always done. What this bounds is the far side,
     * where the emptiness is an artefact of how much has been generated.
     */
    public function testThePastIsNotTheHorizonsBusiness(): void
    {
        self::assertTrue(Horizon::covers('1999-01-01', '2026-09-13'));
    }

    /** The generator's window starts tomorrow and ends on the horizon. */
    public function testTheGeneratorFillsExactlyWhatIsOffered(): void
    {
        self::assertSame([1, Horizon::DAYS], Horizon::window());
    }
}
