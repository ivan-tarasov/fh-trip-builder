<?php

namespace TripBuilder;

use Exception;

class Timer
{
    private const ACCURACY_DEFAULT = 3;

    private static float|string $startTime;
    private static float|string $endTime;

    public static function start(): void
    {
        self::$startTime = microtime(true);
    }

    public static function stop(): void
    {
        self::$endTime = microtime(true);
    }

    /**
     * @throws Exception
     */
    public static function getExecutionTime(int $accuracy = self::ACCURACY_DEFAULT): string
    {
        if (self::$startTime && self::$endTime) {
            return number_format(self::$endTime - self::$startTime, $accuracy);
        } else {
            throw new Exception('Timer not started or stopped.');
        }
    }
}
