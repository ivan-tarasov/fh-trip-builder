<?php

use TripBuilder\Cron;
use TripBuilder\Schedule;

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
    | Five named fields -- minute, hour, day, month, weekday -- the same five in
    | the same order as cPanel's editor, so the two can be read against each
    | other without counting positions. Each takes `*`, a number, `a-b`, a
    | comma-separated list, or any of those with `/step`.
    |
    | Written in crontab order, when before what, so an entry reads down the page
    | the way a crontab line reads across it.
    |
    | `Schedule::COMMAND` and not `Cron::COMMAND`: the five fields describe when
    | and this one describes what, and they come from the two classes that own
    | those questions.
    |
    | `Cron::MINUTE` and friends rather than `'minute'`: a mistyped constant is
    | a fatal error on the line that wrote it. All five are required and none
    | defaults to `*`, because a schedule where forgetting the day field turns a
    | monthly task into a daily one is a schedule that reads correctly while
    | doing something else.
    |
    | Crontab and not a vocabulary of this project's own, because it is the
    | notation everybody already reads and a second spelling of the same idea is
    | a second thing to learn. Names (`MON`), the `@daily` aliases and the
    | `? L W #` extensions are not implemented, and a schedule using one is
    | refused when it loads rather than quietly read as something else.
    |
    | A missed occurrence is caught up on the next tick rather than skipped --
    | so this is a crontab's notation with a crontab's weakness removed. **In UTC**, which the entry points pin and
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
        Cron::MINUTE => 0,
        Cron::HOUR => 3,
        Cron::DAY => Cron::EVERY,
        Cron::MONTH => Cron::EVERY,
        Cron::WEEKDAY => Cron::EVERY,
        Schedule::COMMAND => 'currency:rates',
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
        Cron::MINUTE => 15,
        Cron::HOUR => 3,
        Cron::DAY => Cron::EVERY,
        Cron::MONTH => Cron::EVERY,
        Cron::WEEKDAY => Cron::EVERY,
        Schedule::COMMAND => 'db:prune --force',
    ],
    [
        // One day's worth of flights, into whichever days are thinnest
        // (E24.1, #191). The window is ninety days from whenever the generator
        // last ran and does not move on its own, so without this line the site
        // runs out of flights three months after the last manual run: search
        // returns nothing and every route page 404s.
        //
        // 10,000 is a day, and it was measured rather than inherited. On the
        // twenty-five busiest routes almost any density looks fine; on
        // mid-ranked ones -- rank 3,000 of 49,000, which is what an ordinary
        // visitor searches -- the figure that matters is how often a search
        // finds nothing at all:
        //
        //     4,500/day   16 of 25 routes answer   170ms   405,000 rows
        //     8,000/day   22 of 25               221ms   718,000
        //    10,000/day   24 of 25               268ms   896,000
        //
        // At 4,500 more than a third of ordinary routes come back empty. The
        // cost of the last step is about 110 MB and 47ms.
        //
        // This number is also the size control. With the sweep running, the
        // table settles at `nightly x window days` and stops growing -- it had
        // no upper bound at all before these two lines existed.
        //
        // `--level` and not `--day=90`, which would also work and would say
        // what it does more plainly. Levelling is what survives a missed night:
        // two thin days instead of one, found without anybody knowing the run
        // was missed. `--day` is still there for filling one by hand.
        //
        // Before the sweep at :30, so a day is filled before the previous one
        // is swept.
        Cron::MINUTE => 20,
        Cron::HOUR => 3,
        Cron::DAY => Cron::EVERY,
        Cron::MONTH => Cron::EVERY,
        Cron::WEEKDAY => Cron::EVERY,
        Schedule::COMMAND => 'flights:add 10000 --level',
    ],
    [
        // Flights that have departed (E24.2, #192). Nothing removed them until
        // this line existed, so the table only ever grew.
        //
        // No flag to make it safe, unlike the two above, and it does not need
        // one: `bookings.flight_outbound` is a JSON snapshot rather than a
        // foreign key, so nothing sold points at a `flights` row and removing
        // one cannot reach a booking.
        //
        // `:30` leaves `:00` and `:15` alone and, more to the point, leaves the
        // half hour before it free for the daily generator that E24.1 (#191)
        // will add -- a day should be filled before the previous one is swept.
        Cron::MINUTE => 30,
        Cron::HOUR => 3,
        Cron::DAY => Cron::EVERY,
        Cron::MONTH => Cron::EVERY,
        Cron::WEEKDAY => Cron::EVERY,
        Schedule::COMMAND => 'flights:cleaning',
    ],
];
