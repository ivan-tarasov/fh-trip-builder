<?php

/*
|--------------------------------------------------------------------------
| Cache the calendar's fares per cabin, not just per route
|--------------------------------------------------------------------------
|
| The calendar showed one price a day whatever cabin was chosen, so switching to
| business left every figure at its economy value -- a number that was wrong for
| the search the visitor was about to run.
|
| Cabin belongs in the key rather than beside it: the cheapest business day on a
| route is not the cheapest economy day, because the uplift scales with haul and
| not every flight sells every cabin. Two rows, two answers.
|
| Dropped and recreated rather than altered. Adding a column to a primary key
| means rebuilding it anyway, and these two tables are a cache: nothing here is
| the only copy of anything, and the rows come back the first time each route is
| opened. That is the whole reason it was built lazily.
|
*/

return [
    'DROP TABLE IF EXISTS `route_day_price`',
    'DROP TABLE IF EXISTS `route_price_build`',

    'CREATE TABLE `route_day_price` ('
        . ' `from_code` CHAR(3) NOT NULL,'
        . ' `to_code` CHAR(3) NOT NULL,'
        // The CabinClass value -- "economy", "business" and so on.
        . ' `cabin` VARCHAR(16) NOT NULL,'
        . ' `depart_date` DATE NOT NULL,'
        . ' `price` DECIMAL(8,2) NOT NULL,'
        . ' PRIMARY KEY (`from_code`, `to_code`, `cabin`, `depart_date`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_general_ci',

    'CREATE TABLE `route_price_build` ('
        . ' `from_code` CHAR(3) NOT NULL,'
        . ' `to_code` CHAR(3) NOT NULL,'
        . ' `cabin` VARCHAR(16) NOT NULL,'
        . ' `built_at` DATETIME NOT NULL,'
        . ' `covers_until` DATE NOT NULL,'
        . ' PRIMARY KEY (`from_code`, `to_code`, `cabin`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_general_ci',
];
