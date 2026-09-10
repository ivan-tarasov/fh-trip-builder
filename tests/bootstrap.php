<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
|
| Composer's autoloader, and one thing besides: the database settings the app
| already has, put where the integration suite looks for them.
|
| The app reads `$_ENV`, which Dotenv fills from `.env`. IntegrationTestCase
| reads `getenv()`, which Dotenv deliberately does not touch -- createImmutable
| writes to `$_ENV` and `$_SERVER` and leaves the process environment alone.
| So on a developer's machine, with a perfectly good `.env` sitting there, the
| whole integration suite had nothing to connect to: 160 tests skipped and 64
| more failed, and the only way to run them was to export DB_* by hand, which
| nothing in the repository said.
|
| Nothing here overrides a variable that is already set. CI passes DB_* as real
| environment variables and must keep winning, and so must a developer who
| exports one to point at a database on a different port -- which is the
| documented way to run these against something other than what `.env` names.
|
| `safeLoad` and not `load`: a fresh clone has no `.env` until somebody copies
| the example, and the unit suite has no business failing because of it.
|
*/

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET'] as $key) {
    // `false` and not `''`: an empty DB_SOCKET is a deliberate answer -- it is
    // how somebody says "connect over TCP, not the socket `.env` names" -- and
    // filling it back in from `.env` would undo that.
    if (getenv($key) === false && isset($_ENV[$key])) {
        putenv($key . '=' . (string) $_ENV[$key]);
    }
}
