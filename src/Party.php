<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * Who is travelling, and what that does to a fare.
 *
 * The three types are the ones the search form offers and the URL spells: an
 * adult, a child, and an infant on a lap. An infant in a seat was a fourth
 * select on the home page that no browser ever submitted, and nothing
 * downstream knew what to do with it.
 *
 * A fare is quoted per adult. Everyone else is a share of it, which is roughly
 * how airlines sell: a child takes a seat and pays most of the fare and all of
 * the tax, while a lap infant takes no seat, pays a token fare and no tax at
 * all. Those shares are the whole reason a party of four does not simply cost
 * four times a party of one.
 *
 * Nothing here is money. It reports how many of the per-seat price to charge,
 * and the one place that turns a per-seat price into a party price does the
 * multiplying -- see the note on FlightRepository::fare() about SQL and PHP
 * having to agree to the cent.
 */
final readonly class Party
{
    /** Share of the adult base fare each type pays. */
    private const float CHILD_FARE = 0.75;
    private const float INFANT_FARE = 0.10;

    /**
     * Share of the adult tax each type pays. A child is taxed like an adult
     * because a child occupies a seat; a lap infant is not taxed at all.
     */
    private const float CHILD_TAX = 1.0;
    private const float INFANT_TAX = 0.0;

    /** Seats one booking may hold, matching what the form offers. */
    public const int MAX_SEATS = 9;

    public function __construct(
        public int $adults = 1,
        public int $children = 0,
        public int $infants = 0,
    ) {}

    /**
     * A party, or null when the counts do not describe one.
     *
     * Null rather than a silent correction: a search for something that cannot
     * be booked should fail where it was asked for, not quietly become a
     * different search.
     */
    public static function fromCounts(int $adults, int $children, int $infants): ?self
    {
        // Somebody has to be responsible for the booking, a lap needs an adult
        // attached to it, and the aircraft has a limit -- which infants do not
        // count against, because they are not in a seat.
        if ($adults < 1 || $infants > $adults || $adults + $children > self::MAX_SEATS) {
            return null;
        }

        return $children < 0 || $infants < 0 ? null : new self($adults, $children, $infants);
    }

    /** Everyone travelling, lap infants included. */
    public function total(): int
    {
        return $this->adults + $this->children + $this->infants;
    }

    /** Seats taken. A lap infant has none, which is what makes it cheap. */
    public function seats(): int
    {
        return $this->adults + $this->children;
    }

    /** How many adult base fares this party costs. */
    public function fareShare(): float
    {
        return $this->adults + $this->children * self::CHILD_FARE + $this->infants * self::INFANT_FARE;
    }

    /** How many adult tax amounts this party owes. */
    public function taxShare(): float
    {
        return $this->adults + $this->children * self::CHILD_TAX + $this->infants * self::INFANT_TAX;
    }

    /**
     * A per-seat price as what this party pays.
     *
     * @return array{base: float, tax: float}
     */
    public function apply(float $base, float $tax): array
    {
        return [
            'base' => round($base * $this->fareShare(), 2),
            'tax' => round($tax * $this->taxShare(), 2),
        ];
    }

    /**
     * The lower of the two shares, for turning a party-sized limit back into a
     * per-seat one.
     *
     * Base and tax scale differently, so no single divisor is exact. Taking the
     * smaller share makes the per-seat limit the looser of the two readings,
     * which is the safe direction for a filter that prunes: it can keep a row it
     * did not need to, and can never drop one that qualifies.
     */
    public function smallestShare(): float
    {
        return min($this->fareShare(), $this->taxShare());
    }

    /**
     * "2 adults, 1 child" -- what the price is for, in the words the form used.
     */
    public function label(): string
    {
        $parts = [];

        foreach ([
            [$this->adults, 'adult', 'adults'],
            [$this->children, 'child', 'children'],
            [$this->infants, 'infant', 'infants'],
        ] as [$count, $one, $many]) {
            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, $count === 1 ? $one : $many);
            }
        }

        return implode(', ', $parts);
    }
}
