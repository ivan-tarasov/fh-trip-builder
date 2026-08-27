<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TripBuilder\Timer;

final class TimerTest extends TestCase
{
    protected function setUp(): void
    {
        // Timer keeps global static state with no reset() — clear it per test.
        $reflection = new ReflectionClass(Timer::class);
        $reflection->setStaticPropertyValue('startTime', null);
        $reflection->setStaticPropertyValue('endTime', null);
    }

    public function testReturnsFormattedDurationWithGivenAccuracy(): void
    {
        Timer::start();
        Timer::stop();

        $elapsed = Timer::getExecutionTime(3);

        // number_format(diff, 3) — e.g. "0.000"; always N digits after the point.
        self::assertMatchesRegularExpression('/^\d+\.\d{3}$/', $elapsed);
    }

    public function testThrowsWhenNotStarted(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Timer not started or stopped.');

        Timer::getExecutionTime();
    }

    public function testThrowsWhenStartedButNotStopped(): void
    {
        Timer::start();

        $this->expectException(Exception::class);

        Timer::getExecutionTime();
    }
}
