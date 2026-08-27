<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Csrf;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsCreatedAndStable(): void
    {
        $token = Csrf::token();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame($token, Csrf::token(), 'token() must be stable within a session');
    }

    public function testValidAcceptsTheSessionToken(): void
    {
        $token = Csrf::token();

        self::assertTrue(Csrf::isValid($token));
    }

    public function testInvalidRejectsWrongMissingOrEmpty(): void
    {
        Csrf::token();

        self::assertFalse(Csrf::isValid('not-the-token'));
        self::assertFalse(Csrf::isValid(null));
        self::assertFalse(Csrf::isValid(''));
    }

    public function testValidFailsWhenNoSessionToken(): void
    {
        self::assertFalse(Csrf::isValid('anything'));
    }
}
