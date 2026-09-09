<?php

declare(strict_types=1);

namespace TripBuilder;

use Throwable;
use TripBuilder\Database\Connection;
use TripBuilder\Repository\CurrencyRateRepository;

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
final class Money
{
    /**
     * The request's currency, resolved once.
     *
     * Static because the callers are static themselves -- Helper::sliderCaption
     * is, and ItineraryPresenter is built with `new` in a dozen places -- and
     * threading a currency through all of them would be a constructor argument
     * in every class between here and a controller. Tests reset it.
     */
    private static ?self $active = null;

    public function __construct(
        private readonly Currency $currency,
        /** Units of $currency per 1 CAD. Always a multiplication -- see the catalogue. */
        private readonly float $rate,
    ) {}

    /**
     * The currency this visitor is being shown, at today's rate.
     *
     * Memoised for the request. Resolving it costs one query returning thirty
     * rows, and a search page asks for a price two hundred times.
     *
     * A visitor on the base currency -- the default, and every crawler, and
     * anybody who has never touched the switcher -- takes the early return and
     * makes **no query at all**. The rates table is only read once somebody has
     * actually chosen something else.
     *
     * The rate is looked up here rather than in the constructor because this is
     * the one place that may: a booking is drawn at the rate it was made at,
     * years after that rate stopped being current, and it passes its own pair
     * in. See BookingPresenter.
     *
     * No rate for the chosen currency means the base currency, not an
     * unconverted number wearing a foreign symbol. A missing row is a table
     * nobody has refreshed, and showing Canadian dollars honestly beats showing
     * yen figures that are secretly dollars.
     */
    public static function active(): self
    {
        if (self::$active instanceof self) {
            return self::$active;
        }

        $currency = Currency::active();

        if ($currency->code === Currency::base()->code) {
            return self::$active = self::base();
        }

        $rate = self::rateFor($currency);

        // Null and not zero, and this is worth being careful about: a rate of
        // zero would multiply every price on the site to nothing and render
        // perfectly, so "no rate" has to be a different value from "a rate".
        return self::$active = $rate === null ? self::base() : new self($currency, $rate);
    }

    /**
     * Today's rate for one currency, or null if there is not one to be had.
     *
     * A database that will not answer costs the conversion, not the page --
     * the same fallback every counted footer column takes.
     */
    private static function rateFor(Currency $currency): ?float
    {
        try {
            $rate = new CurrencyRateRepository(Connection::fromEnv())->latest()[$currency->code] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $rate !== null && $rate > 0 ? $rate : null;
    }

    /**
     * Drop the memoised currency.
     *
     * For tests, which change the cookie between cases inside one process. A
     * request only ever resolves this once.
     */
    public static function forget(): void
    {
        self::$active = null;
    }

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
     * @return array{symbol: string, whole: string, cents: ?string, point: string, before: bool, code: string, text: string}
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
     * @return array{base: array{symbol: string, whole: string, cents: ?string, point: string, before: bool, code: string, text: string}, tax: array{symbol: string, whole: string, cents: ?string, point: string, before: bool, code: string, text: string}, total: array{symbol: string, whole: string, cents: ?string, point: string, before: bool, code: string, text: string}}
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
     * The bare converted number, for a caller that will format it itself.
     *
     * The calendar is the only one: it is handed a base and a tax per day and
     * scales them for the party in the browser, so it needs figures rather than
     * strings. Party shares are multipliers, so scaling a converted amount and
     * converting a scaled one give the same answer.
     */
    public function convert(float $cad): float
    {
        return $cad * $this->rate;
    }

    /**
     * The amount with no minor unit at all, floored.
     *
     * The fare strips and the facts tiles quote a price to the dollar -- "from
     * $464" -- which the templates did with `|round(0, 'floor')` on the raw
     * float. Floored on the converted major amount rather than on rounded minor
     * units, because those two disagree: 464.999 floors to 464, but rounded to
     * cents first it becomes 46500 and then floors to 465. One of those matches
     * what the page said before and the other silently adds a dollar.
     *
     * @return array{symbol: string, whole: string, cents: ?string, point: string, before: bool, code: string, text: string}
     */
    public function whole(float $cad): array
    {
        $major = (int) floor($cad * $this->rate);

        return $this->fromMinor($major * 10 ** $this->currency->decimals, withCents: false);
    }

    /**
     * Whole units again, but rounded rather than floored.
     *
     * For a difference rather than a price. "+$465 vs cheapest" is a gap, and
     * the nearest whole unit describes a gap best; a fare quoted "from $464"
     * must never round up, because the fare it is advertising exists at 464.
     * The two callers want opposite things from the same fraction, so they ask
     * different questions.
     *
     * @return array{symbol: string, whole: string, cents: ?string, point: string, before: bool, code: string, text: string}
     */
    public function rounded(float $cad): array
    {
        return $this->fromMinor(
            (int) round($cad * $this->rate) * 10 ** $this->currency->decimals,
            withCents: false,
        );
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

    /** For the slider, which converts a Canadian-dollar value in the browser. */
    public function rate(): float
    {
        return $this->rate;
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
     * @return array{symbol: string, whole: string, cents: ?string, point: string, before: bool, code: string, text: string}
     */
    private function fromMinor(int $minor, bool $withCents = true): array
    {
        $divisor = 10 ** $this->currency->decimals;
        $whole = number_format(
            intdiv(abs($minor), $divisor) * ($minor < 0 ? -1 : 1),
            0,
            $this->currency->point,
            $this->currency->group,
        );

        $cents = $this->currency->decimals === 0 || !$withCents
            ? null
            : str_pad((string) (abs($minor) % $divisor), $this->currency->decimals, '0', STR_PAD_LEFT);

        $number = $cents === null ? $whole : $whole . $this->currency->point . $cents;

        return [
            'symbol' => $this->currency->symbol,
            'whole' => $whole,
            'cents' => $cents,
            // Carried so the markup can put the separator between the two spans
            // itself, and so a template never has to know which currency wants
            // a comma. Same for `before`: SEK is written 1 234,56 kr, and the
            // symbol span has to move rather than the value.
            'point' => $this->currency->point,
            'before' => $this->currency->symbolFirst,
            'code' => $this->currency->code,
            // A non-breaking space before a trailing symbol, so "1 234 kr"
            // cannot leave the "kr" alone at the start of the next line.
            'text' => $this->currency->symbolFirst
                ? $this->currency->symbol . $number
                : $number . "\u{00A0}" . $this->currency->symbol,
        ];
    }
}
