<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * One scheme for the whole site, and the three guards that keep the rule from
 * being worse than not having it.
 *
 * Structural, because Apache is not in CI. The pull request that added this
 * exercised all four branches against the local vhost with a forged `Host`
 * header; what is left for here is that nobody quietly drops a condition.
 */
final class HttpsRedirectTest extends TestCase
{
    /** The lines that matter, with comments and blank lines removed. */
    private static function directives(): string
    {
        $contents = (string) file_get_contents(Helper::getPublicDir() . '/.htaccess.example');

        return implode("\n", array_filter(
            array_map(trim(...), explode("\n", $contents)),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }

    public function testPlainHttpIsRedirectedPermanently(): void
    {
        $directives = self::directives();

        self::assertStringContainsString('RewriteCond %{HTTPS} off', $directives);
        self::assertStringContainsString(
            'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]',
            $directives,
        );
    }

    /**
     * First, so every other rule sees one scheme.
     */
    public function testTheRedirectComesBeforeEveryOtherRule(): void
    {
        preg_match_all('/^RewriteRule .*/m', self::directives(), $found);

        self::assertNotEmpty($found[0]);
        self::assertStringStartsWith(
            'RewriteRule ^ https://',
            $found[0][0],
            'Something now runs before the scheme is settled.',
        );
    }

    /**
     * The loop guard, which is the one that takes the site down when it goes.
     *
     * With the distribution talking to the origin over plain HTTP -- what
     * Cloudflare calls Flexible -- `%{HTTPS}` is off for every request. Without
     * this line the rule then redirects everyone to a URL that arrives back at
     * the origin over HTTP, forever.
     */
    public function testThereIsALoopGuardForATlsTerminatingProxy(): void
    {
        self::assertStringContainsString(
            'RewriteCond %{HTTP:X-Forwarded-Proto} !https',
            self::directives(),
            'Removing this turns the redirect into an infinite loop the day the proxy changes mode.',
        );
    }

    /**
     * Development is plain HTTP on a `.localhost` host with no certificate to
     * redirect to, so the rule has to know not to fire there.
     */
    public function testLocalDevelopmentIsExempt(): void
    {
        $directives = self::directives();

        self::assertStringContainsString('!(^|\.)localhost(:[0-9]+)?$', $directives);
        self::assertStringContainsString('!^127\.0\.0\.1(:[0-9]+)?$', $directives);
    }
}
