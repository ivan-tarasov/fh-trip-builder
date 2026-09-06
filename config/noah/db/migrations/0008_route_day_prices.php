<?php

/*
|--------------------------------------------------------------------------
| The cheapest fare per day, per route, cached
|--------------------------------------------------------------------------
|
| The calendar shows a price under every day. Working one out live is not an
| option: measured on this data, the cheapest one-stop fare for sixty days runs
| 33ms on a quiet route and 2.8s on a busy one, and the query cannot be made to
| behave -- MySQL drives it from a full table scan, and forcing the other join
| order with STRAIGHT_JOIN made it worse, not better. Direct flights alone are
| free to query but answer on a quarter of the days, which reads as a broken
| calendar rather than an honest one.
|
| So it is computed once per route and read back. Reading is a single range scan
| on the primary key.
|
| Two tables, because "no fares on this route" and "never looked" are different
| answers and only one of them is worth computing again. The prices table cannot
| say the first -- it has no rows either way -- so the build marker does.
|
| Prices go stale, which is what `built_at` is for. Nothing here expires a row;
| the reader decides what it will still accept.
|
*/

return [
    'CREATE TABLE IF NOT EXISTS `route_day_price` ('
        . ' `from_code` CHAR(3) NOT NULL,'
        . ' `to_code` CHAR(3) NOT NULL,'
        . ' `depart_date` DATE NOT NULL,'
        // Wide enough for the sum of two legs at the top of the fare range.
        . ' `price` DECIMAL(8,2) NOT NULL,'
        . ' PRIMARY KEY (`from_code`, `to_code`, `depart_date`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_general_ci',

    'CREATE TABLE IF NOT EXISTS `route_price_build` ('
        . ' `from_code` CHAR(3) NOT NULL,'
        . ' `to_code` CHAR(3) NOT NULL,'
        . ' `built_at` DATETIME NOT NULL,'
        // How far ahead the build looked, so a reader can tell whether a blank
        // day was priced and empty or simply outside the window.
        . ' `covers_until` DATE NOT NULL,'
        . ' PRIMARY KEY (`from_code`, `to_code`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_general_ci',
];
