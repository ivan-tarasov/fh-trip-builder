<?php

/*
|--------------------------------------------------------------------------
| Restore the defaults of 0 that never reached the schema
|--------------------------------------------------------------------------
|
| Three columns declared `'default' => 0` in their config and were created with
| no default at all. The clause was emitted behind a truthiness test, and a bare
| 0 is falsy — so the default was dropped without a word, while the eighteen
| columns that wrote it as `[0]` came through fine.
|
| The columns are NOT NULL, so this is not a cosmetic difference: an INSERT that
| omits `airlines`.`is_major` or `airports`.`altitude` fails outright with 1364,
| "Field doesn't have a default value", rather than taking the zero the config
| asked for. `airlines`.`traffic` is nullable and merely defaulted to NULL
| instead of 0.
|
| Install::defaultClause() now treats only `false` and `null` as "no default",
| so a bare 0 emits one and the next column written this way cannot go missing.
| This brings databases that already exist in line with that.
|
| ALTER COLUMN ... SET DEFAULT changes metadata only — no table rebuild, and it
| leaves the column's type and nullability alone.
|
*/

return [
    'ALTER TABLE `airlines` ALTER COLUMN `traffic` SET DEFAULT 0',
    'ALTER TABLE `airlines` ALTER COLUMN `is_major` SET DEFAULT 0',
    'ALTER TABLE `airports` ALTER COLUMN `altitude` SET DEFAULT 0',
];
