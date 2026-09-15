<?php

declare(strict_types=1);

namespace TripBuilder;

use Throwable;
use TripBuilder\Database\Connection;
use TripBuilder\Repository\SettingsRepository;

/**
 * A config value, unless the operator has changed it.
 *
 * `Config::get()` reads a committed file; this reads that same key with one
 * table checked first. The table is empty until somebody opens `/admin/
 * settings` and changes something, so an install nobody has touched answers
 * every key exactly as `Config::get()` would -- there is nothing here for it
 * to override yet (A3.7, #232).
 *
 * Static, and memoised for the process, for the same reason `Money::active()`
 * is: callers are static themselves (`FlightFilters`, `MapView`) or built with
 * `new` in a dozen places, and threading a settings object through all of them
 * would be a constructor argument between here and a controller.
 *
 * Only the keys `PanelSetting` names are ever actually overridden -- nothing
 * writes a row for any other key -- but every `config()` call in a template
 * goes through this now (see `TwigRenderer`), because there is no cheaper way
 * to tell which keys that might be than to make all of them ask.
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private static ?array $overrides = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::$overrides ??= self::load();

        return array_key_exists($key, self::$overrides) ? self::$overrides[$key] : Config::get($key, $default);
    }

    /**
     * Drop the memoised overrides.
     *
     * For tests, and for the admin page itself: saving a change and then
     * reading it back in the same request must not see the answer from
     * before the save.
     */
    public static function forget(): void
    {
        self::$overrides = null;
    }

    /**
     * A database that will not answer costs nothing here: every key still
     * has its config value, the same fallback every counted footer column
     * takes when the table it reads is unreachable.
     *
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        try {
            return new SettingsRepository(Connection::fromEnv())->all();
        } catch (Throwable) {
            return [];
        }
    }
}
