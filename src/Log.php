<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * What the log says, and what ties it together.
 *
 * Before this there were thirty `error_log()` calls and nothing joining them:
 * no request id, no route, no duration. Two errors from one request read
 * exactly like one error from two, and neither says which page it was.
 *
 * Static, like `Timer` and for the same reason: a request id belongs to the
 * process, every caller wants the same one, and threading it through thirty
 * call sites would be a parameter nobody reads. That is the shape this project
 * otherwise avoids -- see the note on `Config` in `Http\Request` -- so it is
 * worth saying that the cost of the shape is paid here and not spread: nothing
 * reads state off this class except the log line itself.
 */
final class Log
{
    private static string $id = '';

    private static float $startedAt = 0.0;

    /**
     * Begin a request. Returns the id so the caller can put it on the response
     * -- somebody reporting a broken page can then quote a header and be found
     * in the log.
     */
    public static function begin(): string
    {
        self::$id = bin2hex(random_bytes(4));
        self::$startedAt = microtime(true);

        return self::$id;
    }

    /**
     * The one line that says a request happened.
     *
     * The front controller registers this as a shutdown function rather than
     * calling it at the end, and that is the whole design: the request most
     * worth having a line for is the one that died -- a fatal, a killed
     * process, an exception thrown past the handler -- and none of those reach
     * the end of anything.
     *
     * Registering it there and not in `begin()` keeps this class out of the
     * process lifecycle, which also means `noah` and the test suite do not
     * quietly grow a shutdown handler apiece.
     */
    public static function finish(string $method, string $path): void
    {
        self::line(sprintf(
            '%s %s %d %sms',
            $method,
            $path,
            http_response_code() ?: 0,
            number_format(self::elapsedMs(), 1),
        ));
    }

    /** The id on this request, or '' on the command line. */
    public static function id(): string
    {
        return self::$id;
    }

    /**
     * Something went wrong, and which request it went wrong on.
     */
    public static function error(string $message): void
    {
        self::line($message);
    }

    private static function line(string $message): void
    {
        error_log(self::$id === '' ? $message : sprintf('[%s] %s', self::$id, $message));
    }

    private static function elapsedMs(): float
    {
        return self::$startedAt === 0.0 ? 0.0 : (microtime(true) - self::$startedAt) * 1000;
    }
}
