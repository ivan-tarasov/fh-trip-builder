<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Api\HttpStatus;

final class HttpStatusTest extends TestCase
{
    public function testBackingValues(): void
    {
        self::assertSame(200, HttpStatus::Ok->value);
        self::assertSame(400, HttpStatus::BadRequest->value);
        self::assertSame(404, HttpStatus::NotFound->value);
        self::assertSame(405, HttpStatus::MethodNotAllowed->value);
    }

    public function testPhrases(): void
    {
        self::assertSame('OK', HttpStatus::Ok->phrase());
        self::assertSame('Not Found', HttpStatus::NotFound->phrase());
        self::assertSame('Method Not Allowed', HttpStatus::MethodNotAllowed->phrase());
        // Preserves the curly apostrophe from the original reason-phrase table.
        self::assertSame("I\u{2019}m a teapot", HttpStatus::ImATeapot->phrase());
    }

    public function testEveryCaseHasAPhrase(): void
    {
        foreach (HttpStatus::cases() as $status) {
            self::assertNotSame('', $status->phrase(), $status->name . ' has no phrase');
        }
    }
}
