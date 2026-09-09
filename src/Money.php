<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * A CAD amount, written in one currency at one rate.
 *
 * Every price in this application is CAD, and stays CAD through the cabin
 * multipliers in SQL, the per-leg rounding those do, and Party::apply(). This
 * is the last step before a number reaches a reader, and the only place a
 * conversion happens. Keeping it here is what leaves intact the agreement
 * FlightRepository::fare() insists on -- that a total computed in SQL and the
 * same total summed in PHP match to the cent -- because on both sides of that
 * comparison the figures are still Canadian dollars.
 *
 * The rate is passed in rather than looked up. A booking has to be written in
 * the currency and at the rate it was made at, years after both have moved, so
 * a class that fetched today's rate for itself could not draw a receipt.
 */
final readonly class Money
{
    public function __construct(
        private Currency $currency,
        /** Units of $currency per 1 CAD. Always a multiplication -- see the catalogue. */
        private float $rate,
    ) {}

    /**
     * The base currency, which is what an unconverted page uses.
     *
     * The rate is stated rather than fetched, because the base currency's rate
     * against itself is arithmetic and not a measurement: one Canadian dollar
     * is one Canadian dollar on every day there has ever been. It is the one
     * rate no table needs to hold and no fetch can be wrong about.
     */
    public static function base(): self
    {
        return new self(Currency::base(), 1.0);
    }

    /**
     * One amount, split for the template.
     *
     * `whole` and `cents` are separate because the markup sizes them
     * differently -- the amount is what people scan and the cents are detail --
     * and `cents` is null where the currency has none rather than "00", so a
     * template can leave out the separator instead of printing a yen price with
     * a decimal point in it.
     *
     * @return array{symbol: string, whole: string, cents: ?string, code: string, text: string}
     */
    public function parts(float $cad): array
    {
        return $this->fromMinor($this->minor($cad));
    }

    /**
     * Base, tax and total, guaranteed to add up.
     *
     * Rounding each of the three on its own does not: at almost any rate there
     * is a figure where round(base) + round(tax) is a unit away from
     * round(base + tax), and the receipt then shows two numbers above a total
     * that is not their sum. Somebody notices that on a page headed "Total
     * paid".
     *
     * So only two of the three are converted. The total is, because it is what
     * the Pay button says and what a receipt is read for; the base is, because
     * it is the fare. Tax is whatever is left -- the line nobody adds up by
     * hand, and the one that can absorb the unit.
     *
     * Subtracted in minor units, never in floats: at 2 decimals a residual of
     * 0.1 + 0.2 does not hold still, and number_format would round it back into
     * view.
     *
     * @return array{base: array{symbol: string, whole: string, cents: ?string, code: string, text: string}, tax: array{symbol: string, whole: string, cents: ?string, code: string, text: string}, total: array{symbol: string, whole: string, cents: ?string, code: string, text: string}}
     */
    public function split(float $base, float $tax): array
    {
        $totalMinor = $this->minor($base + $tax);
        $baseMinor = $this->minor($base);

        return [
            'base' => $this->fromMinor($baseMinor),
            'tax' => $this->fromMinor($totalMinor - $baseMinor),
            'total' => $this->fromMinor($totalMinor),
        ];
    }

    /**
     * The amount as one string, for the places markup cannot go: a `title`
     * attribute, an `<option>` label, a meta description.
     */
    public function text(float $cad): string
    {
        return $this->parts($cad)['text'];
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * The amount in the currency's smallest unit -- cents, or whole yen.
     *
     * The one rounding step. Everything downstream is integer arithmetic and
     * string building, so this is the only place a fraction is decided.
     */
    private function minor(float $cad): int
    {
        return (int) round($cad * $this->rate * 10 ** $this->currency->decimals);
    }

    /**
     * @return array{symbol: string, whole: string, cents: ?string, code: string, text: string}
     */
    private function fromMinor(int $minor): array
    {
        $divisor = 10 ** $this->currency->decimals;
        $whole = number_format(
            intdiv(abs($minor), $divisor) * ($minor < 0 ? -1 : 1),
            0,
            $this->currency->point,
            $this->currency->group,
        );

        $cents = $this->currency->decimals === 0
            ? null
            : str_pad((string) (abs($minor) % $divisor), $this->currency->decimals, '0', STR_PAD_LEFT);

        $number = $cents === null ? $whole : $whole . $this->currency->point . $cents;

        return [
            'symbol' => $this->currency->symbol,
            'whole' => $whole,
            'cents' => $cents,
            'code' => $this->currency->code,
            // A non-breaking space before a trailing symbol, so "1 234 kr"
            // cannot leave the "kr" alone at the start of the next line.
            'text' => $this->currency->symbolFirst
                ? $this->currency->symbol . $number
                : $number . "\u{00A0}" . $this->currency->symbol,
        ];
    }
}
