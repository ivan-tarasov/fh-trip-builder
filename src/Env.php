<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The one place a setting is read from the environment.
 *
 * getenv() first and `$_ENV` second, and the order is the whole point.
 * `Dotenv::createImmutable()` writes `.env` into `$_ENV` and `$_SERVER` but
 * will not overwrite a variable the process environment already holds -- and
 * "will not overwrite" means it does not write it at all. A variable that
 * arrives as a real environment variable is therefore never in `$_ENV`, which
 * is how CI passes its settings and how an Apache `SetEnv` or a cPanel
 * environment passes theirs.
 *
 * Reading `$_ENV` directly returns '' on exactly the hosts that set the
 * variable most deliberately, and returns it quietly. The installer read every
 * table as absent and skipped its additive migrations (Install::tableExists()),
 * and `currency:rates` read an empty currency catalogue because Config could
 * not find APP_ENV and fell back to the `config/` directory itself, which has
 * no files in it. Both look like a working command doing nothing.
 *
 * This had been written by hand twice and worked around once before it was one
 * function; OneEnvReaderTest keeps it at one.
 */
final class Env
{
    /**
     * getenv() tells "unset" (false) apart from a legitimately empty value, so
     * an exported empty string wins over whatever `.env` says -- which is how
     * somebody turns a setting off for a single run.
     */
    public static function get(string $key): string
    {
        $value = getenv($key);

        if ($value !== false) {
            return $value;
        }

        return isset($_ENV[$key]) ? (string) $_ENV[$key] : '';
    }
}
