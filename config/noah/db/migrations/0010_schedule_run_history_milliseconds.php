<?php

/*
|--------------------------------------------------------------------------
| Give schedule_run_history millisecond precision
|--------------------------------------------------------------------------
|
| `started_at`/`finished_at` were a plain `DATETIME` -- whole seconds only --
| so a job that finishes in under a second (most of `alerts:check`,
| `currency:rates`) recorded the same second for both and the Duration
| column on `/admin/schedule/history` could only ever say "0s" for it.
|
| Only this table, not `schedule_runs`: that one feeds `Schedule::due()` and
| `health()`, neither of which cares about anything finer than a minute, and
| widening it too would be a change with no reader.
|
| `Run.php` already writes milliseconds for any row created after this runs,
| so nothing here backfills existing rows -- their true sub-second duration
| was never captured, and a made-up one would be worse than the "0s" it
| already reads.
|
*/

return [
    'ALTER TABLE `schedule_run_history`'
        . ' MODIFY `started_at` DATETIME(3) NOT NULL,'
        . ' MODIFY `finished_at` DATETIME(3) NULL COMMENT "Null while still running, or if the process never came back"',
];
