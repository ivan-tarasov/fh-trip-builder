<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\TripType;

final class TripTypeTest extends TestCase
{
    public function testBackingValues(): void
    {
        self::assertSame('roundtrip', TripType::Roundtrip->value);
        self::assertSame('oneway', TripType::Oneway->value);
    }

    public function testFromRequestResolvesValidValues(): void
    {
        self::assertSame(TripType::Roundtrip, TripType::fromRequest('roundtrip'));
        self::assertSame(TripType::Oneway, TripType::fromRequest('oneway'));
    }

    public function testFromRequestFallsBackToOneway(): void
    {
        self::assertSame(TripType::Oneway, TripType::fromRequest('bogus'));
        self::assertSame(TripType::Oneway, TripType::fromRequest(''));
        self::assertSame(TripType::Oneway, TripType::fromRequest(null));
    }
}
