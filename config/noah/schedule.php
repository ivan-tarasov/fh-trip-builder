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
    |     * * * * * cd /path/to/fh-trip-builder && php noah schedule:run >> ~/logs/schedule.log 2>&1
    |
    | Every minute, so `at` means what it says. A coarser tick would round a
    | task's time up to the next one -- `:20` on an hourly task firing at `:30`,
    | ten minutes late, every hour, with this file still claiming `:20`. An idle
    | tick measured 0.09s, so a minute's cadence costs about two minutes of CPU
    | a day.
    |
    | Before this the crontab was the only record, so a rebuilt server or a
    | reset panel took the schedule with it and nothing here said what had been
    | lost (E16, #167). Adding a command is now a pull request.
    |
    | `every` is a Frequency and `at` is the time it wants: `03:00` for a daily
    | task, `:20` for an hourly one. **In UTC**, which the entry points pin and
    | which is not the server's own clock -- 03:00 here is 23:00 the evening
    | before in Eastern (E17, #171). The crontab line fires on the server's wall
    | clock; what it fires is judged on this one, and the fifteen-minute tick
    | means the two never need to agree. A task is due when nothing has run since
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
