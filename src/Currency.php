<?php

declare(strict_types=1);

namespace TripBuilder;

use RuntimeException;

/**
 * One currency out of the catalogue: what it is called and how to write it.
 *
 * Data only, and deliberately rateless. Converting and formatting are Money's
 * job, because those need a rate, and a rate is not a property of a currency --
 * it is a property of a currency on a day, which is why it lives in a table
 * with a date on it rather than in this list.
 *
 * The catalogue is config/common/currencies.php, which carries the reasoning
 * for the fields, the two deliberate simplifications, and why CAD sits in the
 * list at 1 rather than being special-cased here.
 */
final readonly class Currency
{
    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
        public bool $symbolFirst,
        public int $decimals,
        public string $group,
        public string $point,
    ) {}

    /**
     * The whole catalogue, in the order config lists it.
     *
     * That order is the order the switcher shows: CAD first because it is the
     * default, then the currencies somebody flying from Canada is most likely
     * to want, then the rest. Alphabetical would bury USD under AUD.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        /** @var array<string, array<string, mixed>> $list */
        $list = Config::get('currencies.list', []);
        $currencies = [];

        foreach ($list as $code => $entry) {
            $currencies[$code] = new self(
                code: $code,
                name: (string) $entry['name'],
                symbol: (string) $entry['symbol'],
                symbolFirst: (bool) $entry['before'],
                decimals: (int) $entry['decimals'],
                group: (string) $entry['group'],
                point: (string) $entry['point'],
            );
        }

        return $currencies;
    }

    /**
     * The currency every price is held in, and the one shown to anybody who has
     * not chosen otherwise.
     */
    public static function base(): self
    {
        $code = (string) Config::get('currencies.default', 'CAD');
        $currency = self::all()[$code] ?? null;

        if ($currency === null) {
            // Config naming a default it does not list is a broken install, not
            // a visitor's mistake, and silently picking another currency would
            // reprice the whole site without saying so.
            throw new RuntimeException('currencies.default names "' . $code . '", which currencies.list does not hold');
        }

        return $currency;
    }

    /**
     * A currency by code, or null.
     *
     * Null rather than a fallback, so a caller has to decide what an
     * unrecognised code means. For a cookie that is the base currency; for the
     * rates command it is a row to skip and mention.
     *
     * Exact match only. A cookie is whatever the browser sent, so "usd" and
     * " USD" are somebody editing it by hand and are not honoured -- the
     * switcher only ever writes one of these 31 strings.
     */
    public static function tryFrom(mixed $code): ?self
    {
        return is_string($code) ? (self::all()[$code] ?? null) : null;
    }
}
