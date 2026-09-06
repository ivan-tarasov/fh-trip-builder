<?php

/*
|--------------------------------------------------------------------------
| Keep the calendar's fares as base and tax, not as one number
|--------------------------------------------------------------------------
|
| The calendar showed the same figure for one adult and for nine, because the
| cache held a single per-seat price and nothing multiplied it.
|
| A party cannot be applied to that one number. Base and tax carry different
| shares: a child pays three quarters of the fare but a full adult's tax, and a
| lap infant pays a tenth of the fare and no tax at all. Party::apply() scales
| the two apart and adds them after, and to do the same here the two have to be
| stored apart.
|
| Split rather than multiplied at build time, so the party stays out of the key.
| Ten passenger mixes would otherwise be ten builds of the same route, and the
| numbers can be worked out from these two the moment the party changes --
| without asking the server again at all.
|
| Recreated rather than altered, for the same reason as 0009: this is a cache,
| and it fills itself the first time each route is opened.
|
*/

return [
    'DROP TABLE IF EXISTS `route_day_price`',

    'CREATE TABLE `route_day_price` ('
        . ' `from_code` CHAR(3) NOT NULL,'
        . ' `to_code` CHAR(3) NOT NULL,'
        . ' `cabin` VARCHAR(16) NOT NULL,'
        . ' `depart_date` DATE NOT NULL,'
        // The cheapest itinerary that day, as its own two parts.
        . ' `price_base` DECIMAL(8,2) NOT NULL,'
        . ' `price_tax` DECIMAL(8,2) NOT NULL,'
        . ' PRIMARY KEY (`from_code`, `to_code`, `cabin`, `depart_date`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_general_ci',

    // Every route has to be worked out again: the rows that were there held a
    // total, and there is no way back from that to the two parts of it.
    'DELETE FROM `route_price_build`',
];
