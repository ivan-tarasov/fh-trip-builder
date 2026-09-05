<?php

/*
|--------------------------------------------------------------------------
| Bring booking_passengers in line with the rest of the schema
|--------------------------------------------------------------------------
|
| This table was added on a branch that predates migration 0001, and its config
| declared `utf8` — MySQL's alias for utf8mb3. So the table 0001 was written to
| protect arrived carrying the very fault 0001 removes, and on the two columns
| where it matters most: `first_name` and `last_name`, which hold a passenger
| name as printed on a travel document.
|
| A four-byte character there — emoji are the obvious case, but so are several
| CJK extensions and a number of African and South Asian scripts — raises 1366
| inside the transaction that writes the booking and its travellers together.
| The booking rolls back whole, and the traveller is told something went wrong
| on our side with no hint that their surname was the reason. Retrying cannot
| help them.
|
| `type` and `gender` are then narrowed back to ascii, in that order and for the
| same reason as 0002: CONVERT widens every character column in the table, and
| a one-character IATA-style code has no use for four bytes of it.
|
| The (booking_id, position) index becomes UNIQUE. Two travellers cannot both be
| third in the same party; it was a plain KEY only because the installer could
| not emit anything else, which stopped being true in 0005. All four existing
| rows already hold distinct pairs.
|
*/

return [
    'ALTER TABLE `booking_passengers` CONVERT TO CHARACTER SET utf8mb4',

    'ALTER TABLE `booking_passengers`'
        . ' MODIFY `type` CHAR(1) CHARACTER SET ascii NOT NULL'
        . ' COMMENT "A adult, C child, I infant on lap",'
        . ' MODIFY `gender` CHAR(1) CHARACTER SET ascii NOT NULL'
        . ' COMMENT "F M X"',

    'ALTER TABLE `booking_passengers`'
        . ' DROP INDEX `booking_position`,'
        . ' ADD UNIQUE KEY `booking_position` (`booking_id`, `position`)',
];
