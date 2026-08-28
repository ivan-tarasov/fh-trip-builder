<?php

declare(strict_types=1);

namespace TripBuilder;

use Exception;

class Timer
{
    private const int ACCURACY_DEFAULT = 3;

    private static ?float $startTime = null;
    private static ?float $endTime = null;

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
        if (self::$startTime === null || self::$endTime === null) {
            throw new Exception('Timer not started or stopped.');
        }

        return number_format(self::$endTime - self::$startTime, $accuracy);
    }
}
