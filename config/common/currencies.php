<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | The thirty currencies the switcher offers, and everything needed to print
    | an amount in one. The list is the European Central Bank's reference set,
    | because that is what the rates come from -- see the `currency:rates`
    | command. Adding a currency the ECB does not publish would give it a name
    | in the menu and no rate behind it.
    |
    | RUB and AED are absent for that reason: the ECB stopped publishing RUB in
    | 2022, and never published AED.
    |
    | There are no rates in here, and the absence is the point. A name, a symbol
    | and a minor unit are facts somebody wrote down once. A rate is a
    | measurement taken on a day, and it belongs in `currency_rates` next to the
    | date it was taken. Thirty numbers sitting in this file would be wrong by
    | tomorrow while looking exactly as settled as the symbols beside them.
    |
    | The opening rows come from config/noah/db/seeders/currency_rates.csv, so a
    | fresh clone converts without waiting for a fetch. A later `app:install`
    | cannot clobber fetched rates with them: the table is keyed by code *and*
    | date, so a seeded row and a fetched row are different rows, and the reader
    | takes the most recent one.
    |
    | CAD is listed like any other currency rather than special-cased, so the
    | default takes the same path through the formatter as the rest and cannot
    | rot untested. Its rate against itself is 1 by arithmetic, not by
    | measurement, which is why Money::base() states it rather than looking it
    | up.
    |
    | `decimals` is the ISO 4217 minor unit. Three of these are zero -- JPY, KRW
    | and ISK -- which is not a rounding preference but the currency: there is no
    | such coin as a hundredth of a yen, and printing "¥1,234.00" is wrong in the
    | way a date printed in the wrong calendar is wrong.
    |
    | `group` and `point` are the thousands separator and the decimal mark, and
    | `before` is whether the symbol leads. Without these, "formatted" would mean
    | Canadian digits under a foreign symbol -- 1,234.56 kr where Sweden writes
    | 1 234,56 kr -- which reads as broken to exactly the visitor who switched.
    |
    | The group separator for those is a non-breaking space (U+00A0), not a
    | plain one, so a price never wraps in the middle of its own digits. Same
    | reasoning as the route labels in LayoutData::unbroken().
    |
    | Two known simplifications, both deliberate:
    |
    |   - India groups by two after the first three (₹12,34,567), and
    |     number_format cannot. These print in threes.
    |   - The euro is written €1,234.56 in Ireland and 1.234,56 € in Germany.
    |     One list cannot hold both, and the site is English, so the Irish form
    |     is used.
    |
    */

    'default' => 'CAD',

    'list' => [
        'CAD' => ['name' => 'Canadian Dollar', 'symbol' => '$', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'USD' => ['name' => 'United States Dollar', 'symbol' => 'US$', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'EUR' => ['name' => 'Euro', 'symbol' => '€', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'NZD' => ['name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'HKD' => ['name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'SGD' => ['name' => 'Singapore Dollar', 'symbol' => 'S$', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'MXN' => ['name' => 'Mexican Peso', 'symbol' => 'MX$', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'BRL' => ['name' => 'Brazilian Real', 'symbol' => 'R$', 'before' => true, 'decimals' => 2, 'group' => '.', 'point' => ','],
        'CHF' => ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'before' => true, 'decimals' => 2, 'group' => '’', 'point' => '.'],
        'CNY' => ['name' => 'Chinese Renminbi Yuan', 'symbol' => '¥', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'ILS' => ['name' => 'Israeli New Shekel', 'symbol' => '₪', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'MYR' => ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'PHP' => ['name' => 'Philippine Peso', 'symbol' => '₱', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'THB' => ['name' => 'Thai Baht', 'symbol' => '฿', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'TRY' => ['name' => 'Turkish Lira', 'symbol' => '₺', 'before' => true, 'decimals' => 2, 'group' => '.', 'point' => ','],
        'ZAR' => ['name' => 'South African Rand', 'symbol' => 'R', 'before' => true, 'decimals' => 2, 'group' => ',', 'point' => '.'],
        'IDR' => ['name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'before' => true, 'decimals' => 2, 'group' => '.', 'point' => ','],

        // Zero decimals. Not a preference -- there is no hundredth of a yen.
        'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥', 'before' => true, 'decimals' => 0, 'group' => ',', 'point' => '.'],
        'KRW' => ['name' => 'South Korean Won', 'symbol' => '₩', 'before' => true, 'decimals' => 0, 'group' => ',', 'point' => '.'],
        'ISK' => ['name' => 'Icelandic Króna', 'symbol' => 'kr', 'before' => false, 'decimals' => 0, 'group' => '.', 'point' => ','],

        // Symbol after the number, and a space or a stop where English puts a
        // comma. These are the entries that make `group` and `point` worth
        // carrying at all.
        'SEK' => ['name' => 'Swedish Krona', 'symbol' => 'kr', 'before' => false, 'decimals' => 2, 'group' => "\u{00A0}", 'point' => ','],
        'NOK' => ['name' => 'Norwegian Krone', 'symbol' => 'kr', 'before' => false, 'decimals' => 2, 'group' => "\u{00A0}", 'point' => ','],
        'DKK' => ['name' => 'Danish Krone', 'symbol' => 'kr.', 'before' => false, 'decimals' => 2, 'group' => '.', 'point' => ','],
        'PLN' => ['name' => 'Polish Złoty', 'symbol' => 'zł', 'before' => false, 'decimals' => 2, 'group' => "\u{00A0}", 'point' => ','],
        'CZK' => ['name' => 'Czech Koruna', 'symbol' => 'Kč', 'before' => false, 'decimals' => 2, 'group' => "\u{00A0}", 'point' => ','],
        'HUF' => ['name' => 'Hungarian Forint', 'symbol' => 'Ft', 'before' => false, 'decimals' => 2, 'group' => "\u{00A0}", 'point' => ','],
        'RON' => ['name' => 'Romanian Leu', 'symbol' => 'lei', 'before' => false, 'decimals' => 2, 'group' => '.', 'point' => ','],
    ],
];
