<?php

declare(strict_types=1);
/**
 * TripBuilder Config Class
 *
 * @author Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version 1.0.2
 */

namespace TripBuilder;

use Exception;

class Config
{
    private const string CONFIG_DIRECTORY = 'config';

    protected static array $configData = [];

    /**
     * @throws Exception
     */
    public function __construct(?string $environment = null)
    {
        // Clear config data array to prevent mixing data with multiple time using
        self::$configData = [];

        $environment ??= Env::get('APP_ENV');

        // Refused rather than defaulted. An empty name makes the path
        // `config/`, which exists and holds no `.php` files, so the config
        // loads as empty instead of failing: `Config::get()` answers null for
        // everything, every caller sees a plausible nothing, and the first
        // thing to notice was `currency:rates` reporting that no currencies
        // are configured.
        if ($environment === '') {
            throw new Exception('APP_ENV is not set, so there is no config environment to load.');
        }

        $directory = sprintf(
            '%s/%s/%s',
            Helper::getRootDir(),
            self::CONFIG_DIRECTORY,
            $environment,
        );

        if (is_dir($directory)) {
            $configFiles = scandir($directory);

            foreach ($configFiles as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $configName = pathinfo($file, PATHINFO_FILENAME);
                    $configPath = $directory . DIRECTORY_SEPARATOR . $file;

                    self::$configData[$configName] = require $configPath;
                }
            }
        } else {
            throw new Exception("Config directory not found: $directory");
        }
    }

    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return static::$configData;
        }

        $segments = explode('.', $key);
        $value = static::$configData;

        foreach ($segments as $segment) {
            if (isset($value[$segment])) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }
}
