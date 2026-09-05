<?php

/*
|--------------------------------------------------------------------------
| Narrow the fixed-width code columns to ASCII
|--------------------------------------------------------------------------
|
| Every CHAR column in the schema holds something that is ASCII by definition:
| IATA airport, airline, aircraft and cabin codes, ISO country codes, an md5
| hex digest, a booking reference, a card's last four digits, a Y-m-d date.
| None of them can ever hold a non-ASCII character.
|
| They were all utf8mb4, which reserves four bytes per character whether or not
| they are used — so CHAR(3) cost 12 bytes and the `search` primary key cost
| 128. Migration 0001 made this worse: converting the tables from utf8mb3 to
| utf8mb4 widened every one of these from three bytes per character to four.
| That migration was still right — free-text columns genuinely needed it — but
| it caught the code columns along with them.
|
| The cost lands hardest in indexes. InnoDB copies the primary key into every
| secondary index, and `flights` has three indexes leading with an airport or
| an airline code. Narrower keys mean more entries per page and fewer pages
| read for the same lookup.
|
| Columns are grouped one ALTER per table so each table rebuilds once rather
| than once per column. Every join pair is converted in the same run --
| flights.departure_airport with airports.code, airports.country_code with
| countries.code, and so on -- because a join whose sides disagree on charset
| has to convert one of them and can no longer seek the index.
|
| `flights` is last: it is the one large table, and a CONVERT rebuilds it.
|
| `booking_passengers` is not here. It does not exist on this branch, and its
| own config declares the charset it is created with.
|
*/

return [
    'ALTER TABLE `aircraft`'
        . ' MODIFY `code` CHAR(3) CHARACTER SET ascii NOT NULL',

    'ALTER TABLE `aircraft_cabins`'
        . ' MODIFY `aircraft` CHAR(3) CHARACTER SET ascii NOT NULL'
        . ' COMMENT "IATA type code, joins aircraft.code",'
        . ' MODIFY `cabin` CHAR(1) CHARACTER SET ascii NOT NULL'
        . ' COMMENT "IATA cabin code: Y W C F"',

    'ALTER TABLE `airlines`'
        . ' MODIFY `code` CHAR(2) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `country` CHAR(2) CHARACTER SET ascii NULL',

    'ALTER TABLE `airports`'
        . ' MODIFY `code` CHAR(3) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `city_code` CHAR(3) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `country_code` CHAR(2) CHARACTER SET ascii NOT NULL',

    'ALTER TABLE `bookings`'
        . ' MODIFY `reference` CHAR(6) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `card_last4` CHAR(4) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `passenger_gender` CHAR(1) CHARACTER SET ascii NOT NULL',

    'ALTER TABLE `countries`'
        . ' MODIFY `code` CHAR(2) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `code_iso_3` CHAR(3) CHARACTER SET ascii NOT NULL',

    'ALTER TABLE `fare_brands`'
        . ' MODIFY `code` CHAR(2) CHARACTER SET ascii NOT NULL',

    'ALTER TABLE `search`'
        . ' MODIFY `hash` CHAR(32) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `from_code` CHAR(3) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `to_code` CHAR(3) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `depart` CHAR(10) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `return` CHAR(10) CHARACTER SET ascii NULL',

    // Last: the largest table, and the one whose indexes this is really for.
    'ALTER TABLE `flights`'
        . ' MODIFY `airline` CHAR(2) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `departure_airport` CHAR(3) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `arrival_airport` CHAR(3) CHARACTER SET ascii NOT NULL,'
        . ' MODIFY `aircraft` CHAR(3) CHARACTER SET ascii NULL,'
        . ' MODIFY `fare_brand` CHAR(2) CHARACTER SET ascii NULL',
];
