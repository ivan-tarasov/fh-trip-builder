<?php

/*
|--------------------------------------------------------------------------
| Seed `cities:images` into the schedule
|--------------------------------------------------------------------------
|
| `scheduled_jobs` is DB-backed, not code-backed (G19, #377), so adding
| `cities:images` to `SCHEDULABLE_COMMAND_CLASSES` only makes it an option
| an operator can pick from `/admin/schedule` -- it does not make the
| command run anywhere. `alerts:check` (C6, #155) shipped without a seed
| for exactly this reason and never ran on a real server (G21, #385) until
| `0009_seed_alerts_check_schedule.php` fixed it. This is that same fix,
| made up front instead of after the gap is found again.
|
| Weekly, not nightly: a city's Wikipedia photo and summary are treated as
| stale after 90 days (`Images::STALE_AFTER_DAYS`), so a nightly run would
| spend 231 requests against a rate-limited source checking cities that
| are due for the next several months. Sunday 04:00, after the Saturday
| night backup/prune window, is free of any other job in this schedule.
|
| `INSERT IGNORE`, the same reason `0009` uses it: `command` is unique, and
| an environment where somebody already added `cities:images` by hand
| through `/admin/schedule` already has this row under whatever timing
| they gave it -- `IGNORE` leaves that alone rather than failing the
| migration on the conflict.
|
*/

return [
    'INSERT IGNORE INTO `scheduled_jobs` (command, minute, hour, day, month, weekday)'
        . " VALUES ('cities:images', '0', '4', '*', '*', '0')",
];
