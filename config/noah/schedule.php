<?php

use TripBuilder\Frequency;

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduled commands
    |--------------------------------------------------------------------------
    |
    | What runs without anybody asking, and when. The server holds one cron
    | line and this file holds the rest:
    |
    |     0,15,30,45 * * * * cd /path/to/fh-trip-builder && php noah schedule:run
    |
    | Spelled out rather than as a step, because the step form contains the two
    | characters that end a block comment and took this file's parse with it.
    |
    | Before this the crontab was the only record, so a rebuilt server or a
    | reset panel took the schedule with it and nothing here said what had been
    | lost (E16, #167). Adding a command is now a pull request.
    |
    | `every` is a Frequency and `at` is the time it wants: `03:00` for a daily
    | task, `:20` for an hourly one. A task is due when nothing has run since
    | the moment it was last supposed to, so a missed tick catches up on the
    | next one rather than skipping the day.
    |
    | Times are staggered rather than shared. Both of these open a database
    | connection and one of them calls out over HTTP; there is no reason for
    | them to do it in the same minute.
    |
    */

    [
        // Exchange rates for the currency switcher. Published once a day, so
        // reading them more often than that is asking the same question again.
        'command' => 'currency:rates',
        'every' => Frequency::Daily,
        'at' => '03:00',
    ],
    [
        // The retention policy (E9, #146): bookings whose flight left more than
        // 90 days ago, the passengers on them, and finished rate-limit
        // counters.
        //
        // `--force` because a scheduled run that only lists would print into a
        // cron log nobody reads and delete nothing. The deploy runbook has the
        // operator run it dry once and read that list before this line is ever
        // enabled -- that is the only time the first sweep is visible.
        'command' => 'db:prune --force',
        'every' => Frequency::Daily,
        'at' => '03:15',
    ],
];
