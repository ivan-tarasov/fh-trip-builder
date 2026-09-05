<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Party;

final class PartyTest extends TestCase
{
    public function testALapInfantTakesNoSeatButIsStillTravelling(): void
    {
        $party = new Party(adults: 2, children: 1, infants: 1);

        self::assertSame(4, $party->total());
        self::assertSame(3, $party->seats());
    }

    public function testAPartyOfOneAdultCostsExactlyThePerSeatPrice(): void
    {
        $party = new Party();

        self::assertSame(1.0, $party->fareShare());
        self::assertSame(1.0, $party->taxShare());
        self::assertSame(['base' => 500.0, 'tax' => 80.0], $party->apply(500.0, 80.0));
    }

    public function testAChildPaysMostOfTheFareAndAllOfTheTax(): void
    {
        // A child takes a seat, so it is taxed like an adult.
        $party = new Party(adults: 1, children: 1);

        self::assertSame(1.75, $party->fareShare());
        self::assertSame(2.0, $party->taxShare());
        self::assertSame(['base' => 875.0, 'tax' => 160.0], $party->apply(500.0, 80.0));
    }

    public function testALapInfantPaysATokenFareAndNoTax(): void
    {
        $party = new Party(adults: 1, infants: 1);

        self::assertSame(1.1, $party->fareShare());
        self::assertSame(1.0, $party->taxShare());
        self::assertSame(['base' => 550.0, 'tax' => 80.0], $party->apply(500.0, 80.0));
    }

    public function testAMixedPartyIsNotSimplyTheHeadCount(): void
    {
        // The whole point: four people, but not four times the price.
        $party = new Party(adults: 2, children: 1, infants: 1);

        self::assertSame(4, $party->total());
        self::assertSame(2.85, $party->fareShare());
        self::assertSame(3.0, $party->taxShare());
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public static function impossibleParties(): array
    {
        return [
            [0, 0, 0],   // nobody is responsible for the booking
            [0, 2, 0],   // children cannot travel alone here
            [1, 0, 2],   // more laps than adults to sit on
            [5, 5, 0],   // ten seats
            [1, -1, 0],
            [1, 0, -1],
        ];
    }

    #[DataProvider('impossibleParties')]
    public function testCountsThatDoNotDescribeAPartyAreRejected(int $a, int $c, int $i): void
    {
        // Null rather than a silent correction: a search for something that
        // cannot be booked should fail where it was asked for.
        self::assertNull(Party::fromCounts($a, $c, $i));
    }

    public function testTheSeatLimitCountsSeatsNotHeads(): void
    {
        // Nine seats plus nine laps is a legal, if improbable, booking.
        self::assertNotNull(Party::fromCounts(9, 0, 9));
        self::assertNotNull(Party::fromCounts(5, 4, 5));
        self::assertNull(Party::fromCounts(5, 5, 0));
    }

    public function testTheSmallestShareIsTheSafeDivisorForAPruningLimit(): void
    {
        // The two shares move independently: an infant lifts the fare share
        // but not the tax share, a child lifts the tax share more than the fare
        // share. A pruning bound takes whichever is smaller, so it can only ever
        // be too generous.
        self::assertSame(1.0, new Party(adults: 1, infants: 1)->smallestShare());
        self::assertSame(1.75, new Party(adults: 1, children: 1)->smallestShare());
    }

    /**
     * @return list<array{0: Party, 1: string}>
     */
    public static function labels(): array
    {
        return [
            [new Party(), '1 adult'],
            [new Party(adults: 2), '2 adults'],
            [new Party(adults: 2, children: 1), '2 adults, 1 child'],
            [new Party(adults: 2, children: 2, infants: 1), '2 adults, 2 children, 1 infant'],
            [new Party(adults: 1, infants: 1), '1 adult, 1 infant'],
        ];
    }

    #[DataProvider('labels')]
    public function testTheLabelSaysWhoThePriceIsFor(Party $party, string $expected): void
    {
        self::assertSame($expected, $party->label());
    }
}
