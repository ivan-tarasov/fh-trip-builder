<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency rates DB table
    |--------------------------------------------------------------------------
    |
    | What one Canadian dollar bought, on a day. Written by `currency:rates`
    | from the European Central Bank's reference rates, and read by the
    | formatter to convert every price on the site.
    |
    | Keyed by code *and* date rather than by code alone, so this is a history
    | and not a single current figure. Three things follow from that, and all
    | three are the reason for it:
    |
    |   - Rate graphs become a later feature with no schema change. Frankfurter
    |     serves date ranges, so backfilling is a command argument.
    |   - The seeded rows cannot be clobbered. `app:install` upserts every
    |     seeder CSV and refreshes every column, so a table keyed on `code`
    |     alone would have its fetched rates overwritten by the committed ones
    |     on every install. Keyed on the date too, a seeded row and a fetched
    |     row are simply different rows.
    |   - A rate is never silently replaced. Yesterday's figure stays readable,
    |     which matters because a booking records the rate it was made at and
    |     somebody may want to check it.
    |
    | `rate_date` is the ECB's own publication date out of the payload, not the
    | moment we fetched. Those differ: the ECB publishes on working days around
    | 16:00 CET, so a Sunday fetch records Friday's rate. Storing the fetch time
    | as the date would invent a Saturday rate that the ECB never published, and
    | running the command twice on one afternoon would write two rows where
    | there is one fact.
    |
    | `fetched_at` keeps the other half of that: when we asked. It is what tells
    | a stale table from an unattempted one, the same job route_price_build does
    | for the fare cache.
    |
    | DECIMAL(18,8) rather than a float, for the reason every other money column
    | here is DECIMAL: a rate multiplied into a price has to give the same
    | answer twice. Eight decimal places is more than the ECB publishes and ten
    | integer digits is far more than any rate needs -- IDR, the largest in the
    | set, is about 12,700 per dollar.
    |
    | The currency's name, symbol and minor unit are not here. Those are facts
    | somebody wrote once and they live in config/common/currencies.php; this
    | table holds only what a measurement changes.
    |
    */

    'primary' => 'code, rate_date',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'code',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'ISO 4217 code, and a key into currencies.list',
        ],
        [
            'name' => 'rate_date',
            'type' => 'date',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The day the ECB published this rate, not the day we fetched it',
        ],
        [
            'name' => 'rate',
            'type' => 'decimal',
            'length' => '18,8',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Units of this currency per 1 CAD, so converting is a multiplication',
        ],
        [
            'name' => 'fetched_at',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],
];
