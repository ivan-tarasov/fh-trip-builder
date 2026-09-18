<?php

/*
|--------------------------------------------------------------------------
| Seed `alerts:check` into the schedule
|--------------------------------------------------------------------------
|
| `scheduled_jobs` is deliberately DB-backed, not code-backed (G19, #377):
| an edit there takes effect on the next tick, not a deploy. That is also
| why a *new* command never appears in it by itself -- `0008_seed_scheduled_jobs.php`
| only ever seeded the four jobs the old static file carried at the moment
| the table replaced it, once, for every environment that already existed.
| Nothing since has added a new command to any environment but the one an
| operator remembered to edit by hand.
|
| `alerts:check` (C6, #155) shipped without one of these, found only when
| it never ran on a real server (G21, #385) -- the admin panel's own
| `/admin/schedule` had been used to add it locally, which reaches exactly
| the database sitting behind that browser tab. This is the fix for that
| specific gap, not a standing policy: it runs once, for this one command,
| the same way `0008` did for its own four. A schedule entry an operator
| later disables or re-times stays that way -- this cannot re-seed over it,
| because a migration never reapplies once run (`db:migrate` records it in
| `schema_migrations`), which is the whole reason this is a migration and
| not the CSV seeder's `ON DUPLICATE KEY UPDATE` row.
|
| Daily at 03:45, right after the other overnight jobs (currency:rates
| 03:00, db:prune 03:15, flights:add/flights:cleaning 03:20/03:30) so it
| reads a freshly-rebuilt route_day_price rather than yesterday's -- the
| same timing chosen when it was first added by hand.
|
| `INSERT IGNORE`, unlike `0008`'s own plain `INSERT`: that one could
| assume an empty table, since nothing before it had ever written a row.
| This one cannot -- `command` is unique, and the exact scenario this
| migration exists to close (an environment where somebody already added
| `alerts:check` by hand through `/admin/schedule`) is one where the row
| is already there under whatever timing they gave it. `IGNORE` leaves
| that alone rather than failing the whole migration run on the conflict.
|
*/

return [
    'INSERT IGNORE INTO `scheduled_jobs` (command, minute, hour, day, month, weekday)'
        . " VALUES ('alerts:check', '45', '3', '*', '*', '*')",
];
