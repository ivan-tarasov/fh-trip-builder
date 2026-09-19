<?php

/*
|--------------------------------------------------------------------------
| Rename the scheduled `cities:images` row to `cities:content`
|--------------------------------------------------------------------------
|
| The command itself was renamed (`Noah\Cities\Images` -> `Noah\Cities\Content`,
| `cities:images` -> `cities:content`) once `countries:content` shipped right
| beside it and made "images" read as the wrong name for a command that has
| cached a text summary since C15 (#405).
|
| `0011_seed_cities_images_schedule.php` is not edited for this: it is a
| historical record of what actually ran, and it genuinely inserted the row
| under the old name. Renaming the running command without also renaming
| this row would leave `scheduled_jobs` pointing at a command that no longer
| exists -- silently never running again, the exact class of gap `0009` and
| `0011` themselves each exist to close, just from the opposite direction.
|
| `UPDATE ... WHERE command = 'cities:images'` rather than a delete-and-reinsert:
| this preserves whatever `minute`/`hour`/`day`/`month`/`weekday` an operator
| may have already changed by hand through `/admin/schedule`, the same reason
| `0009` and `0011` use `INSERT IGNORE` rather than overwriting an
| already-customised row.
|
*/

return [
    "UPDATE `scheduled_jobs` SET command = 'cities:content' WHERE command = 'cities:images'",
];
