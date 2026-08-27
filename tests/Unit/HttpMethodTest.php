<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Api\HttpMethod;

final class HttpMethodTest extends TestCase
{
    public function testBackingValues(): void
    {
        self::assertSame('GET', HttpMethod::Get->value);
        self::assertSame('POST', HttpMethod::Post->value);
    }

    public function testFromString(): void
    {
        self::assertSame(HttpMethod::Get, HttpMethod::from('GET'));
        self::assertNull(HttpMethod::tryFrom('BOGUS'));
    }
}
