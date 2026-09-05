<?php

/*
|--------------------------------------------------------------------------
| Record how many days a recorded search covered
|--------------------------------------------------------------------------
|
| A search may now leave on any of up to three days at each end, and the row
| that records it held one date per direction and nothing to say how wide the
| window was.
|
| Without these, a flexible search and the single-date search that starts on the
| same day are the same row: the counter for one bumps the other, and the home
| page's `?hash=` link rebuilds whichever spelling was written first. Since
| hashFor() folds the spans into the digest only when they are greater than one,
| every row already recorded keeps its hash and its count.
|
| Default 1, which is what every existing row means and what most new ones will.
|
*/

return [
    'ALTER TABLE `search`'
        . ' ADD COLUMN `depart_span` TINYINT NOT NULL DEFAULT 1 AFTER `depart`,'
        . ' ADD COLUMN `return_span` TINYINT NOT NULL DEFAULT 1 AFTER `return`',
];
