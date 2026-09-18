<?php

/*
|--------------------------------------------------------------------------
| Seed `flights:explore` into the schedule
|--------------------------------------------------------------------------
|
| `flights:explore` (C8, #157) is a new command as of this branch, and
| `0009`'s own docblock already names the gap this closes: a new command
| never appears in `scheduled_jobs` by itself, only through a migration
| like this one or an operator adding it by hand through `/admin/schedule`.
| Without this, the homepage's Explore prices would only ever warm on
| whichever server somebody remembered to add the job to.
|
| Daily at 03:40 -- after `flights:cleaning` (03:30), so it works out
| routes over a network that has already had the day's `flights:add` and
| lost yesterday's departed flights, and before `alerts:check` (03:45),
| the other job reading `route_day_price` that overnight.
|
| `INSERT IGNORE`, same reason as `0009`: `command` is unique, and an
| environment where this was already added by hand keeps whatever timing
| it was given rather than having this migration overwrite it.
|
*/

return [
    'INSERT IGNORE INTO `scheduled_jobs` (command, minute, hour, day, month, weekday)'
        . " VALUES ('flights:explore', '40', '3', '*', '*', '*')",
];
