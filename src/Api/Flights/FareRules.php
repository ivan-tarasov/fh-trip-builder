<?php

declare(strict_types=1);

namespace TripBuilder\Api\Flights;

/**
 * What a ticket may and may not do, as a page can say it.
 *
 * Each permission is an integer ordered loosest-to-strictest downwards, which
 * is what lets `strictest()` fold a connecting itinerary into one set: a fare is
 * only as generous as the tightest leg in it, so a journey whose second leg
 * forbids changes forbids changes, whatever the first leg allowed.
 */
final readonly class FareRules
{
    // checked_bag / changes / cancellation / seat_selection
    public const int NOT_ALLOWED = 0;
    public const int FOR_A_FEE = 1;
    public const int INCLUDED = 2;

    public function __construct(
        public string $title,
        public int $carryOn,
        public int $checkedBag,
        public int $changes,
        public int $cancellation,
        public int $seatSelection,
        public bool $refundable,
    ) {}

    /**
     * @param array<string, mixed> $row a fare_brands row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            title: (string) $row['title'],
            carryOn: (int) $row['carry_on'],
            checkedBag: (int) $row['checked_bag'],
            changes: (int) $row['changes'],
            cancellation: (int) $row['cancellation'],
            seatSelection: (int) $row['seat_selection'],
            refundable: (bool) $row['refundable'],
        );
    }

    /**
     * The rules that hold across a whole journey.
     *
     * The strictest value wins on every count, and the title comes from the
     * strictest leg overall — naming a journey "Flex" because one leg of it was
     * would promise a refund the ticket does not carry.
     *
     * @param non-empty-list<self> $legs
     */
    public static function strictest(array $legs): self
    {
        $tightest = $legs[0];
        $rules = new self(
            title: $tightest->title,
            carryOn: $tightest->carryOn,
            checkedBag: $tightest->checkedBag,
            changes: $tightest->changes,
            cancellation: $tightest->cancellation,
            seatSelection: $tightest->seatSelection,
            refundable: $tightest->refundable,
        );

        foreach (array_slice($legs, 1) as $leg) {
            if ($leg->generosity() < $tightest->generosity()) {
                $tightest = $leg;
            }

            $rules = new self(
                title: $tightest->title,
                carryOn: min($rules->carryOn, $leg->carryOn),
                checkedBag: min($rules->checkedBag, $leg->checkedBag),
                changes: min($rules->changes, $leg->changes),
                cancellation: min($rules->cancellation, $leg->cancellation),
                seatSelection: min($rules->seatSelection, $leg->seatSelection),
                refundable: $rules->refundable && $leg->refundable,
            );
        }

        return $rules;
    }

    /**
     * The rules as a traveller reads them, strictest first.
     *
     * Baggage leads because it is what people check, and the restrictions that
     * cost money later — changes, cancellation — come before the conveniences.
     *
     * @return list<array{text: string, allowed: bool}>
     */
    public function lines(): array
    {
        return [
            ['text' => 'Bring a personal item', 'allowed' => true],
            $this->carryOn === self::NOT_ALLOWED
                ? ['text' => 'No carry-on bag included', 'allowed' => false]
                : ['text' => 'Bring a carry-on bag', 'allowed' => true],
            match ($this->checkedBag) {
                self::NOT_ALLOWED => ['text' => 'No checked bag included', 'allowed' => false],
                self::FOR_A_FEE => ['text' => 'Pay to bring a checked bag', 'allowed' => false],
                default => ['text' => 'Checked bag included', 'allowed' => true],
            },
            match ($this->changes) {
                self::NOT_ALLOWED => ['text' => 'Changes not allowed', 'allowed' => false],
                self::FOR_A_FEE => ['text' => 'Changes for a fee', 'allowed' => false],
                default => ['text' => 'Free changes', 'allowed' => true],
            },
            match ($this->cancellation) {
                self::NOT_ALLOWED => ['text' => 'Cancellations not allowed', 'allowed' => false],
                self::FOR_A_FEE => ['text' => 'Cancel for a fee', 'allowed' => false],
                default => ['text' => 'Free cancellation', 'allowed' => true],
            },
            match ($this->seatSelection) {
                self::NOT_ALLOWED => ['text' => 'Seat assigned at check-in', 'allowed' => false],
                self::FOR_A_FEE => ['text' => 'Pay to choose your seat', 'allowed' => false],
                default => ['text' => 'Choose your seat', 'allowed' => true],
            },
        ];
    }

    /**
     * How much this fare allows overall, for picking the leg that names the
     * journey. A sum is enough: the fares are ordered tiers, not a lattice.
     */
    private function generosity(): int
    {
        return $this->carryOn + $this->checkedBag + $this->changes
            + $this->cancellation + $this->seatSelection + (int) $this->refundable;
    }
}
