<?php
/**
 * TripBuilder Config Class
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   1.0.1
 */

namespace TripBuilder;

use TripBuilder\Debug\dBug;

class Config
{
    const CONFIG_DIRECTORY = 'config';

    protected static array $configData = [];

    /**
     * @throws \Exception
     */
    public function __construct($environment = false)
    {
        // Clear data array to prevent mixing data with multiple time using
        self::$configData = [];

        $directory = sprintf(
            '%s/%s/%s',
            Helper::getRootDir(),
            self::CONFIG_DIRECTORY,
            $environment ?: $_ENV['APP_ENV']
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
            throw new \Exception("Config directory not found: $directory");
        }
    }

    /**
     * @param null $key
     * @param null $default
     * @return mixed|null
     */
    public static function get($key = null, $default = null): mixed
    {
        if ($key === null) {
            return static::$configData;
        }

        $segments = explode('.', $key);
        $value    = static::$configData;

        foreach ($segments as $segment) {
            if (isset($value[$segment])) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * @param $key
     * @param $value
     * @return void
     */
    public static function set($key, $value): void
    {
        $segments = explode('.', $key);
        $config =& static::$configData;

        foreach ($segments as $segment) {
            if (!isset($config[$segment]) || !is_array($config[$segment])) {
                $config[$segment] = [];
            }

            $config =& $config[$segment];
        }

        $config = $value;
    }

}
