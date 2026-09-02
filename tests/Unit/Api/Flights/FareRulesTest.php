<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Api\Flights;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Api\Flights\FareRules;

final class FareRulesTest extends TestCase
{
    private static function basic(): FareRules
    {
        return new FareRules(
            title: 'Basic',
            carryOn: 0,
            checkedBag: FareRules::NOT_ALLOWED,
            changes: FareRules::NOT_ALLOWED,
            cancellation: FareRules::NOT_ALLOWED,
            seatSelection: FareRules::NOT_ALLOWED,
            refundable: false,
        );
    }

    private static function standard(): FareRules
    {
        return new FareRules(
            title: 'Standard',
            carryOn: 1,
            checkedBag: FareRules::FOR_A_FEE,
            changes: FareRules::FOR_A_FEE,
            cancellation: FareRules::FOR_A_FEE,
            seatSelection: FareRules::FOR_A_FEE,
            refundable: false,
        );
    }

    private static function flex(): FareRules
    {
        return new FareRules(
            title: 'Flex',
            carryOn: 1,
            checkedBag: FareRules::INCLUDED,
            changes: FareRules::INCLUDED,
            cancellation: FareRules::INCLUDED,
            seatSelection: FareRules::INCLUDED,
            refundable: true,
        );
    }

    public function testOneLegIsItsOwnRules(): void
    {
        $rules = FareRules::strictest([self::flex()]);

        self::assertSame('Flex', $rules->title);
        self::assertTrue($rules->refundable);
        self::assertSame(FareRules::INCLUDED, $rules->changes);
    }

    public function testAJourneyIsOnlyAsGenerousAsItsTightestLeg(): void
    {
        // The case this exists for: a Flex outbound connecting onto a Basic leg
        // is not a Flex ticket. Selling it as one promises a refund the ticket
        // does not carry.
        $rules = FareRules::strictest([self::flex(), self::basic(), self::standard()]);

        self::assertSame('Basic', $rules->title);
        self::assertFalse($rules->refundable);
        self::assertSame(FareRules::NOT_ALLOWED, $rules->changes);
        self::assertSame(FareRules::NOT_ALLOWED, $rules->checkedBag);
        self::assertSame(0, $rules->carryOn);
    }

    public function testEveryCountIsFoldedSeparately(): void
    {
        // A leg can be the strictest on one count and not another, so the fold
        // is per column rather than "pick the worst fare and use it whole".
        $mixedA = new FareRules(
            title: 'A',
            carryOn: 1,
            checkedBag: FareRules::INCLUDED,
            changes: FareRules::NOT_ALLOWED,
            cancellation: FareRules::INCLUDED,
            seatSelection: FareRules::INCLUDED,
            refundable: true,
        );
        $mixedB = new FareRules(
            title: 'B',
            carryOn: 1,
            checkedBag: FareRules::NOT_ALLOWED,
            changes: FareRules::INCLUDED,
            cancellation: FareRules::FOR_A_FEE,
            seatSelection: FareRules::INCLUDED,
            refundable: true,
        );

        $rules = FareRules::strictest([$mixedA, $mixedB]);

        self::assertSame(FareRules::NOT_ALLOWED, $rules->checkedBag);
        self::assertSame(FareRules::NOT_ALLOWED, $rules->changes);
        self::assertSame(FareRules::FOR_A_FEE, $rules->cancellation);
        self::assertSame(FareRules::INCLUDED, $rules->seatSelection);
    }

    public function testRefundableSurvivesOnlyIfEveryLegIs(): void
    {
        self::assertTrue(FareRules::strictest([self::flex(), self::flex()])->refundable);
        self::assertFalse(FareRules::strictest([self::flex(), self::standard()])->refundable);
    }

    public function testTheOrderLegsArriveInDoesNotChangeTheAnswer(): void
    {
        $forwards = FareRules::strictest([self::flex(), self::standard(), self::basic()]);
        $backwards = FareRules::strictest([self::basic(), self::standard(), self::flex()]);

        self::assertSame($forwards->title, $backwards->title);
        self::assertSame($forwards->changes, $backwards->changes);
        self::assertSame($forwards->checkedBag, $backwards->checkedBag);
        self::assertSame($forwards->refundable, $backwards->refundable);
    }

    #[DataProvider('lineProvider')]
    public function testEachRuleReadsAsWhatItAllows(string $expected, bool $allowed, FareRules $rules): void
    {
        $texts = array_column($rules->lines(), 'text');

        self::assertContains($expected, $texts);

        foreach ($rules->lines() as $line) {
            if ($line['text'] === $expected) {
                self::assertSame($allowed, $line['allowed']);
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: FareRules}>
     */
    public static function lineProvider(): array
    {
        return [
            'basic has no carry-on' => ['No carry-on bag included', false, self::basic()],
            'basic cannot change' => ['Changes not allowed', false, self::basic()],
            'basic gets a seat at check-in' => ['Seat assigned at check-in', false, self::basic()],
            'standard pays for a bag' => ['Pay to bring a checked bag', false, self::standard()],
            'standard pays to change' => ['Changes for a fee', false, self::standard()],
            'flex includes a bag' => ['Checked bag included', true, self::flex()],
            'flex changes free' => ['Free changes', true, self::flex()],
            // True of every fare, so it is stated rather than left out.
            'everyone gets a personal item' => ['Bring a personal item', true, self::basic()],
        ];
    }
}
