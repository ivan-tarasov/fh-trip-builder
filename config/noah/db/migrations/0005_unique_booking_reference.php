<?php

/*
|--------------------------------------------------------------------------
| Make the booking reference unique
|--------------------------------------------------------------------------
|
| `bookings`.`reference` carried a plain, non-unique index. Nothing in the
| schema stopped two bookings from answering to the same six characters.
|
| unusedReference() picks a code, asks whether it is taken, and then inserts —
| three steps that are not one, so two checkouts running together can both be
| told the same code is free. Its comment reasons that looping is "cheaper than
| explaining a duplicate key to whoever hits one", which assumed a duplicate key
| existed to hit. There was none: the loser of that race got a booking, silently,
| under a reference someone else already held.
|
| That reference is what a traveller quotes down a phone, and findByReference()
| resolves it with LIMIT 1 — so a duplicate does not fail, it answers with
| whichever row MySQL reaches first.
|
| The loop stays: it still avoids the collision nearly every time. What changes
| is what happens when it does not — an error rather than a second booking.
|
| Nothing to clean up first; all 8 rows already hold distinct references.
|
*/

return [
    'ALTER TABLE `bookings`'
        . ' DROP INDEX `reference`,'
        . ' ADD UNIQUE KEY `reference` (`reference`)',
];
