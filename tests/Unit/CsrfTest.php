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
    /**
     * The browser mirrors the two names, and has to keep mirroring them.
     *
     * JavaScript cannot read a PHP constant, so `global.js` writes both out --
     * the field on the one fetch that sends a token in the body, the header on
     * the three that send it in a header. That is the cross-language half of
     * this contract and the half nothing else checks: rename either constant
     * and PHP keeps agreeing with itself while the browser quietly stops being
     * believed.
     *
     * This is what the field name was already doing. The constant said
     * `_csrf`, checkout obeyed it, and the AJAX guard read a literal
     * `csrf_token` -- the *session key's* name -- which the footer form and
     * `global.js` both wrote out to match. Three spellings, one of them the
     * constant, and nothing failing.
     */
    public function testTheBrowserSpellsBothNamesTheWayThisClassDoes(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../public/js/global.js');

        self::assertStringContainsString(
            "body.append('" . Csrf::FIELD . "'",
            $script,
            'the day-prices fetch posts a field name this class does not define',
        );

        self::assertStringContainsString(
            "'" . Csrf::HEADER . "':",
            $script,
            'the fetches send a header name this class does not define',
        );

        // And the name it used to send is gone, rather than lingering beside
        // the new one where it would look deliberate.
        self::assertStringNotContainsString("body.append('csrf_token'", $script);
    }

}
