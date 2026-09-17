<?php

/*
|--------------------------------------------------------------------------
| Seed the four jobs config/noah/schedule.php used to carry
|--------------------------------------------------------------------------
|
| G19 (#377) moves the schedule's source of truth from that static, git-
| tracked file into `scheduled_jobs`, editable from `/admin/schedule`. This
| runs once -- `db:migrate` records it in `schema_migrations` and never
| reapplies it -- which is the whole reason it is a migration and not a CSV
| seeder row: the seeder's `ON DUPLICATE KEY UPDATE` reapplies on every
| `app:install` and would overwrite an operator's later edit back to this.
|
| Same four commands, same timing, same comments the old file carried next
| to each one.
|
*/

return [
    // Exchange rates for the currency switcher. Published once a day, so
    // reading them more often than that is asking the same question again.
    'INSERT INTO `scheduled_jobs` (command, minute, hour, day, month, weekday)'
        . " VALUES ('currency:rates', '0', '3', '*', '*', '*')",
    // The retention policy (E9, #146). `--force` because a scheduled run
    // that only lists would print into a cron log nobody reads and delete
    // nothing.
    'INSERT INTO `scheduled_jobs` (command, minute, hour, day, month, weekday)'
        . " VALUES ('db:prune --force', '15', '3', '*', '*', '*')",
    // One day's worth of flights, into whichever days are thinnest (E24.1,
    // #191). 10,000 and `--level` are both measured, not guessed -- see the
    // old `config/noah/schedule.php` history for the numbers.
    'INSERT INTO `scheduled_jobs` (command, minute, hour, day, month, weekday)'
        . " VALUES ('flights:add 10000 --level', '20', '3', '*', '*', '*')",
    // Flights that have departed (E24.2, #192).
    'INSERT INTO `scheduled_jobs` (command, minute, hour, day, month, weekday)'
        . " VALUES ('flights:cleaning', '30', '3', '*', '*', '*')",
];
