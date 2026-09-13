<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TripBuilder\Cron;

/**
 * A hand-rolled crontab parser, which is a thing that is wrong for a year
 * before anybody notices.
 *
 * The schedule runs unattended, so every one of these is a fact nobody will be
 * watching when it matters.
 */
final class CronTest extends TestCase
{
    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment);
    }

    public function testEveryMinuteMatchesEveryMinute(): void
    {
        $cron = Cron::parse('* * * * *');

        self::assertTrue($cron->matches(self::at('2026-09-12 14:31:00')));
        self::assertTrue($cron->matches(self::at('2026-01-01 00:00:00')));
    }

    public function testAFixedTimeMatchesOnlyThatMinute(): void
    {
        $cron = Cron::parse('15 3 * * *');

        self::assertTrue($cron->matches(self::at('2026-09-12 03:15:00')));
        self::assertFalse($cron->matches(self::at('2026-09-12 03:16:00')));
        self::assertFalse($cron->matches(self::at('2026-09-12 04:15:00')));
    }

    public function testStepsDivideTheRange(): void
    {
        $cron = Cron::parse('*/15 * * * *');

        foreach (['00', '15', '30', '45'] as $minute) {
            self::assertTrue($cron->matches(self::at('2026-09-12 14:' . $minute . ':00')));
        }

        foreach (['01', '14', '31'] as $minute) {
            self::assertFalse($cron->matches(self::at('2026-09-12 14:' . $minute . ':00')));
        }
    }

    public function testListsAndRangesAndSteppedRanges(): void
    {
        self::assertTrue(Cron::parse('0 1,13 * * *')->matches(self::at('2026-09-12 13:00:00')));
        self::assertFalse(Cron::parse('0 1,13 * * *')->matches(self::at('2026-09-12 12:00:00')));

        self::assertTrue(Cron::parse('0 9-17 * * *')->matches(self::at('2026-09-12 17:00:00')));
        self::assertFalse(Cron::parse('0 9-17 * * *')->matches(self::at('2026-09-12 18:00:00')));

        // Every other hour between 8 and 16.
        $cron = Cron::parse('0 8-16/4 * * *');
        self::assertTrue($cron->matches(self::at('2026-09-12 12:00:00')));
        self::assertFalse($cron->matches(self::at('2026-09-12 14:00:00')));
    }

    /**
     * A bare number with a step means "from here to the end of the field".
     *
     * `5/10` in the minute field is 5, 15, 25, 35, 45, 55 — not just 5.
     */
    public function testANumberWithAStepRunsToTheEndOfTheField(): void
    {
        $cron = Cron::parse('5/10 * * * *');

        self::assertTrue($cron->matches(self::at('2026-09-12 14:05:00')));
        self::assertTrue($cron->matches(self::at('2026-09-12 14:55:00')));
        self::assertFalse($cron->matches(self::at('2026-09-12 14:06:00')));
    }

    /**
     * Sunday is both 0 and 7, and they have to mean the same day.
     */
    public function testSundayIsBothZeroAndSeven(): void
    {
        $sunday = self::at('2026-09-13 03:00:00');

        self::assertSame('Sunday', $sunday->format('l'));
        self::assertTrue(Cron::parse('0 3 * * 0')->matches($sunday));
        self::assertTrue(Cron::parse('0 3 * * 7')->matches($sunday));
    }

    /**
     * Day-of-month and day-of-week are an OR when both are restricted.
     *
     * `0 3 13 * 5` is "the 13th, **and** every Friday" — not "Friday the 13th".
     * This is the detail a hand-rolled parser gets wrong, and it stays wrong
     * until the day the two happen to coincide.
     */
    public function testDayOfMonthAndWeekdayAreAnOrAndNotAnAnd(): void
    {
        $cron = Cron::parse('0 3 13 * 5');

        // The 13th, which is a Sunday in September 2026.
        $thirteenth = self::at('2026-09-13 03:00:00');
        self::assertSame('Sunday', $thirteenth->format('l'));
        self::assertTrue($cron->matches($thirteenth), 'the 13th did not match');

        // A Friday that is not the 13th.
        $friday = self::at('2026-09-11 03:00:00');
        self::assertSame('Friday', $friday->format('l'));
        self::assertTrue($cron->matches($friday), 'a Friday did not match');

        // Neither.
        self::assertFalse($cron->matches(self::at('2026-09-12 03:00:00')));
    }

    /**
     * With only one of the two restricted, it is an AND — which is the same
     * thing said differently, since the unrestricted one matches every day.
     */
    public function testOnlyOneDayFieldRestrictedBehavesNormally(): void
    {
        $cron = Cron::parse('0 3 * * 5');

        self::assertTrue($cron->matches(self::at('2026-09-11 03:00:00')));
        self::assertFalse($cron->matches(self::at('2026-09-12 03:00:00')));
    }

    public function testPreviousFindsTheMostRecentMatch(): void
    {
        $previous = Cron::parse('*/15 * * * *')->previous(self::at('2026-09-12 14:31:00'), 60);

        self::assertNotNull($previous);
        self::assertSame('2026-09-12 14:30', $previous->format('Y-m-d H:i'));
    }

    /**
     * Nothing inside the window is "not due", not "due at a time unknown".
     */
    public function testPreviousAnswersNullWhenNothingIsInTheWindow(): void
    {
        self::assertNull(Cron::parse('0 3 * * *')->previous(self::at('2026-09-12 14:31:00'), 10));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unsupported(): array
    {
        return [
            ['0 3 * * MON'],
            ['@daily'],
            ['0 3 ? * *'],
            ['0 3 L * *'],
            ['0 3 * * 5#2'],
            ['0 3 15W * *'],
        ];
    }

    /**
     * What is not implemented is refused, not guessed at.
     *
     * A schedule quietly misunderstood is worse than one that will not start:
     * the first runs something at a time nobody chose, and does it unattended.
     */
    #[DataProvider('unsupported')]
    public function testUnsupportedSyntaxIsRefused(string $expression): void
    {
        $this->expectException(RuntimeException::class);

        Cron::parse($expression);
    }

    public function testNamedFieldsBuildTheSameThingAsAnExpression(): void
    {
        $named = Cron::fromFields([
            Cron::MINUTE => 15,
            Cron::HOUR => 3,
            Cron::DAY => Cron::EVERY,
            Cron::MONTH => Cron::EVERY,
            Cron::WEEKDAY => Cron::EVERY,
        ]);

        self::assertSame('15 3 * * *', $named->expression());
        self::assertTrue($named->matches(self::at('2026-09-12 03:15:00')));
    }

    /**
     * A field left out is refused, not treated as `*`.
     *
     * Defaulting is the dangerous choice: forgetting the day field would turn a
     * monthly task into a daily one and nothing about the line would look
     * wrong. The message names the command, because a schedule has several.
     */
    public function testAMissingFieldIsRefusedAndTheMessageNamesTheCommand(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/`db:prune --force` is missing month, weekday/');

        Cron::fromFields([
            Cron::MINUTE => 0,
            Cron::HOUR => 3,
            Cron::DAY => Cron::EVERY,
        ], 'db:prune --force');
    }

    public function testTheFieldsAreInCrontabOrder(): void
    {
        self::assertSame(['minute', 'hour', 'day', 'month', 'weekday'], Cron::FIELDS);
    }

    public function testTheWrongNumberOfFieldsIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has 4 field/');

        Cron::parse('0 3 * *');
    }

    public function testAValueOutsideItsFieldIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside 0-23/');

        Cron::parse('0 25 * * *');
    }
}
