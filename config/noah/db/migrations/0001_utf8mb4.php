<?php

/*
|--------------------------------------------------------------------------
| Convert every table to utf8mb4
|--------------------------------------------------------------------------
|
| The tables were created as `utf8`, which is MySQL's alias for utf8mb3 —
| three bytes, no astral plane. The connection has always asked for utf8mb4
| (Connection::dsn), so the two disagreed, and any four-byte character reaching
| a text column raised `1366 Incorrect string value`.
|
| That is not exotic input: emoji, several CJK extensions, and a number of
| African and South Asian scripts are all four bytes. A passenger name carrying
| one failed *inside* the checkout transaction, so the booking rolled back and
| the traveller was shown "Something went wrong on our side" with no hint that
| their surname was the reason, and retrying could never help.
|
| The table configs now declare utf8mb4, which covers a fresh install. This
| converts the databases that already exist — the installer is additive and
| cannot change a charset.
|
| No collation is named on purpose: MySQL and MariaDB pick different defaults
| for utf8mb4 and both are fine here, whereas naming utf8mb4_0900_ai_ci would
| fail outright on MariaDB.
|
| `flights` is the slow one — a CONVERT rebuilds the table, and it holds around
| 700k rows. Expect this migration to take a minute or two.
|
| Only the tables that exist on this branch are listed. A table added later
| declares utf8mb4 in its own config and is created correctly; one that already
| exists elsewhere as utf8mb3 needs its own migration alongside it.
|
*/

return array_map(
    static fn(string $table): string => sprintf('ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4', $table),
    [
        'aircraft',
        'aircraft_cabins',
        'airlines',
        'airports',
        'bookings',
        'countries',
        'fare_brands',
        'search',
        // Last: the largest table, so everything else is already converted if
        // this one has to be interrupted.
        'flights',
    ],
);
