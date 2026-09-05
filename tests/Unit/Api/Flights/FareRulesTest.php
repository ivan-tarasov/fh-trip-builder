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

    public function testRulesSurviveTheRoundTripABookingStoresThemThrough(): void
    {
        // A booking keeps toArray() as JSON, because the legs it was folded from
        // are deleted once they have flown. fromRow() reads a fare_brands row
        // and toArray() writes that same shape on purpose -- if the two drift, a
        // missing key reads back as 0, which is a real permission value, so the
        // booking would quietly come back as a stricter fare than was sold.
        //
        // Both fixtures avoid 0 and false on every field for that reason: flex
        // is generous throughout, and folding it with standard lands every
        // permission on FOR_A_FEE rather than on NOT_ALLOWED.
        $cases = [
            'generous, refundable' => self::flex(),
            'folded across two legs' => FareRules::strictest([self::flex(), self::standard()]),
        ];

        foreach ($cases as $label => $sold) {
            $restored = FareRules::fromRow(
                json_decode((string) json_encode($sold->toArray()), true),
            );

            self::assertEquals($sold, $restored, $label);
            self::assertSame($sold->lines(), $restored->lines(), $label);
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
