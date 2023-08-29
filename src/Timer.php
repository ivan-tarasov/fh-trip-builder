<?php

namespace TripBuilder;

class Timer
{
    const ACCURACY_DEFAULT = 3;

    private static $startTime;

    private static $endTime;

    public static function start() {
        self::$startTime = microtime(true);
    }

    public static function stop() {
        self::$endTime = microtime(true);
    }

    /**
     * @param int $accuracy
     * @return string
     * @throws \Exception
     */
    public static function getExecutionTime(int $accuracy = self::ACCURACY_DEFAULT): string
    {
        if (self::$startTime && self::$endTime) {
            return number_format(self::$endTime - self::$startTime, $accuracy);
        } else {
            throw new \Exception("Timer not started or stopped.");
        }
    }
}
