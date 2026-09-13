<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;
use TripBuilder\Http\Input;
use TripBuilder\Http\Request;
use TripBuilder\Http\SecurityHeaders;

/**
 * The headers a page carries, asserted where CI can see them.
 *
 * This is the reason they are sent from PHP and not from `.htaccess`: a rule
 * in that file can only be read here, never run, and the way `<IfModule>`
 * fails is by doing nothing at all.
 */
final class SecurityHeadersTest extends TestCase
{
    private static function request(bool $secure): Request
    {
        return new Request(
            query: new Input(),
            body: new Input(),
            cookies: new Input(),
            secure: $secure,
        );
    }

    public function testEveryResponseCarriesTheFourUnconditionalHeaders(): void
    {
        $headers = SecurityHeaders::forRequest(self::request(false));

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        self::assertSame('camera=(), microphone=(), geolocation=()', $headers['Permissions-Policy']);
    }

    public function testHstsIsSentOverHttps(): void
    {
        $headers = SecurityHeaders::forRequest(self::request(true));

        self::assertSame('max-age=86400', $headers['Strict-Transport-Security'] ?? null);
    }

    /**
     * Not over plain HTTP, which is what local development is.
     *
     * A browser is supposed to ignore the header on an insecure response. One
     * that did not would refuse `fh-trip-builder.localhost:8888` afterwards,
     * and the only cure is a settings page most people do not know exists.
     */
    public function testHstsIsNotSentOverPlainHttp(): void
    {
        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            SecurityHeaders::forRequest(self::request(false)),
        );
    }

    /**
     * The max-age is short, and stays short until E8.2 (#144) is live.
     *
     * Every browser that reads this refuses `http://` for the duration, and
     * nothing the server sends later can shorten what a browser already
     * stored. A year here would be irreversible for a year.
     */
    public function testTheHstsMaxAgeIsStillSmallEnoughToBeWrongAbout(): void
    {
        preg_match(
            '/max-age=(\d+)/',
            SecurityHeaders::forRequest(self::request(true))['Strict-Transport-Security'],
            $found,
        );

        self::assertArrayHasKey(1, $found, 'HSTS should carry a max-age');
        self::assertLessThanOrEqual(
            86400,
            (int) $found[1],
            'Growing this is a decision. Make it in the same commit as the redirect, not before.',
        );
    }

    /**
     * `clipboard-write` is not denied, because the site uses it.
     *
     * The copy buttons on code blocks call `navigator.clipboard`. A blanket
     * Permissions-Policy would stop them, and stop them silently.
     */
    public function testThePermissionsPolicyLeavesTheClipboardAlone(): void
    {
        $policy = SecurityHeaders::forRequest(self::request(true))['Permissions-Policy'];

        self::assertStringNotContainsString('clipboard', $policy);
        self::assertStringContainsString('navigator.clipboard', self::globalJs());
    }

    /**
     * The class is only worth anything if something calls it.
     *
     * A header that is defined and never sent looks exactly like a header that
     * works, from in here.
     */
    public function testTheFrontControllerSendsThem(): void
    {
        self::assertStringContainsString(
            'SecurityHeaders::send($request);',
            (string) file_get_contents(Helper::getPublicDir() . '/index.php'),
        );
    }

    private static function globalJs(): string
    {
        return (string) file_get_contents(Helper::getPublicDir() . '/js/global.js');
    }
}
