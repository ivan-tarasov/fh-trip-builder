<?php
/**
 * TripBuilder Config Class
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.2.2
 */

namespace TripBuilder\Config;

class MainConfig
{
    public static function getRootDir(): string
    {
        return dirname(__FILE__, 3);
    }

}
