<?php
/**
 * TripBuilder Config Class
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.3.2
 */

namespace TripBuilder;

use TripBuilder\Debug\dBug;

class Config
{
    const CONFIG_DIRECTORY = 'config';

    const CONFIG_FILE = 'config.php';

    /**
     * @param $class
     * @param $key
     * @param $default
     * @return mixed|null
     */
    public static function get($class = null, $key = null, $default = null): mixed
    {
        require sprintf(
            '%s/%s/%s/%s',
            Helper::getRootDir(),
            self::CONFIG_DIRECTORY,
            $_ENV['APP_ENV'],
            self::CONFIG_FILE
        );

        if (isset($config[$class])) {
            if (empty($key)) {
                return $config[$class];
            }

            $keys  = explode('.', $key);
            $value = $config[$class];

            foreach ($keys as $key) {
                if (is_array($value) && isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return $default;
                }
            }

            return $value;
        }

        return $config;
    }

}
