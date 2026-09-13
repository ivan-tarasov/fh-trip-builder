<?php

/*
|--------------------------------------------------------------------------
| Give every flight a UTC instant beside its local departure time
|--------------------------------------------------------------------------
|
| `flights.departure_time` is local at the departure airport: 08:00 at YUL is
| 08:00 there, which is what a ticket says and what the page prints. Ten queries
| compared it against `NOW()` to decide whether a flight had left — a wall clock
| against an instant. Airport offsets in the seeded data run from -11.00 to
| +13.00, so the answer was out by up to thirteen hours, in both directions
| (E20, #180).
|
| `app:install` adds the column, because that is what it is for. This fills it,
| which the installer cannot: the value is a function of the row and of the
| airport it departs from.
|
| Only rows that have not got one. The column is nullable, so every row the
| installer has just added holds null until this runs — and a second run then
| skips everything the first one did rather than recomputing it.
|
| `INTERVAL (o.timezone * 60) MINUTE` and not HOUR: `airports.timezone` is
| `decimal(4,2)` and the data holds 5.75 (Nepal), 5.50, -2.50 (Newfoundland).
| Hours would put those three-quarters of an hour out, which is the kind of
| wrong that looks right.
|
| Subtract, because local is ahead of UTC east of Greenwich: 08:00 in Tokyo
| (+9) is 23:00 UTC the day before.
|
| Flights whose airport has no row are left null rather than guessed at. They cannot be searched either way — every query joins the airport
| — and a wrong instant would be worse than an obviously absent one.
|
*/

return [
    'UPDATE `flights` f'
        . ' JOIN `airports` o ON o.code = f.departure_airport'
        . ' SET f.departure_utc = f.departure_time - INTERVAL (o.timezone * 60) MINUTE'
        . ' WHERE f.departure_utc IS NULL',
];
