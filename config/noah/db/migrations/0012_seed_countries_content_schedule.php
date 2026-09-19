<?php

/*
|--------------------------------------------------------------------------
| Seed `countries:content` into the schedule
|--------------------------------------------------------------------------
|
| `scheduled_jobs` is DB-backed, not code-backed (G19, #377), so adding
| `countries:content` to `SCHEDULABLE_COMMAND_CLASSES` only makes it an
| option an operator can pick from `/admin/schedule` -- it does not make
| the command run anywhere. Both `alerts:check` (#385) and `cities:content`
| (C10, #395) shipped without a seed for exactly this reason and never ran
| on a real server until a migration fixed it. This is that same fix, made
| up front instead of after the gap is found again.
|
| Weekly, not nightly: a country's Wikipedia summary is treated as stale
| after 90 days (`Content::STALE_AFTER_DAYS`), the same reasoning
| `cities:content` uses. Sunday 04:15, fifteen minutes after
| `cities:content`'s own Sunday 04:00 slot -- the smaller of the two sets
| (93 countries against 231 cities) finishing well clear of whatever comes
| next in the schedule.
|
| `INSERT IGNORE`, the same reason `0009` and `0011` use it: `command` is
| unique, and an environment where somebody already added
| `countries:content` by hand through `/admin/schedule` already has this
| row under whatever timing they gave it -- `IGNORE` leaves that alone
| rather than failing the migration on the conflict.
|
*/

return [
    'INSERT IGNORE INTO `scheduled_jobs` (command, minute, hour, day, month, weekday)'
        . " VALUES ('countries:content', '15', '4', '*', '*', '0')",
];
