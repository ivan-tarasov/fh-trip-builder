<?php

/*
|--------------------------------------------------------------------------
| Store the search dates as DATE
|--------------------------------------------------------------------------
|
| `depart` and `return` held 'Y-m-d' in CHAR(10). The format is the one MySQL
| itself uses, so the column was a DATE in everything but type — and being a
| string, it accepted things a date never would.
|
| It did. `return` carried three different spellings of "one way": NULL for most
| rows, and an empty string for twelve of them. Nothing rejected the empty
| string, and Twig's `{% if search.return %}` treats it as falsy, so the home
| page rendered those rows correctly and the flaw stayed invisible there.
|
| Following such a row's `?hash=` link did not. checkHash() passes the column
| through `=== null` to decide one-way versus round trip, an empty string is not
| null, and SearchUrl then read it as a return date — building
| `/search/YUL151026LHR010170Y1`: a round trip returning on 1 January 1970. A
| DATE column cannot hold an empty string, so the ambiguity stops being
| something callers have to remember to handle.
|
| The empty strings are rewritten to NULL first, because MySQL in strict mode
| would reject them on the way to DATE and take the migration down with them.
|
| The write path is already correct: identity() re-reads these values from the
| parsed SearchUrl before record() sees them, so a one-way has stored NULL since
| the short-URL change. These twelve rows predate it.
|
*/

return [
    "UPDATE `search` SET `return` = NULL WHERE `return` = ''",

    'ALTER TABLE `search`'
        . ' MODIFY `depart` DATE NOT NULL,'
        . ' MODIFY `return` DATE NULL',
];
