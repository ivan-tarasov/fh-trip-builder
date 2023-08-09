<?php

namespace TripBuilder;

class Helper
{
    /**
     * Return project root directory
     *
     * @return string
     */
    public static function getRootDir(): string
    {
        return dirname(__FILE__, 2);
    }

    public static function getUrlPath()
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/')['path'];
    }

}