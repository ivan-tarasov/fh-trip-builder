<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * The rule that keeps the repository root from being a website.
 *
 * Apache is not in CI, so nothing here can make a request and read a 403.
 * What can be checked is the file the deploy copies into place: that the
 * allow-list rule is present, that it allows exactly two paths, and that it
 * sits above the front controller. A structural test, and a weaker one than a
 * request -- which is why the pull request that added it also carries the
 * local and production probes, and why E7.3 (#141) exists.
 *
 * It is here because the alternative was remembering. Production served
 * composer.lock and executed files under src/ for as long as nobody happened to
 * type the URL, and the fix is five lines that a later edit to this file could
 * drop without anything noticing.
 */
final class HtaccessAllowListTest extends TestCase
{
    private static function htaccess(): string
    {
        return (string) file_get_contents(Helper::getRootDir() . '/.htaccess.example');
    }

    /** The lines that matter, with comments and blank lines removed. */
    private static function directives(): string
    {
        return implode("\n", array_filter(
            array_map(trim(...), explode("\n", self::htaccess())),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }

    public function testARealFileOrDirectoryIsRefused(): void
    {
        $directives = self::directives();

        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} -f [OR]', $directives);
        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} -d', $directives);
        self::assertMatchesRegularExpression('/RewriteRule \^ - \[F\]/', $directives);
    }

    /**
     * Exactly two things may be served as files. A third exception added here
     * is a decision, and this is where it gets noticed.
     */
    public function testOnlyIndexAndFrontendAreLetThrough(): void
    {
        preg_match_all('/RewriteCond %\{REQUEST_URI\} !\^(\S+)/', self::directives(), $found);

        self::assertSame(
            ['/$', '/index\.php$', '/frontend(/|$)'],
            $found[1],
            'The allow-list has changed. If that is on purpose, change this test in the same commit.',
        );
    }

    /**
     * Above the front controller, not below it.
     *
     * The order does not change what Apache does -- the front controller skips
     * real files anyway -- but it changes what a reader sees first, and the
     * refusal is the rule that matters.
     */
    public function testTheRefusalComesBeforeTheFrontController(): void
    {
        $directives = self::directives();

        self::assertLessThan(
            strpos($directives, 'RewriteRule ^ index.php'),
            strpos($directives, 'RewriteRule ^ - [F]'),
        );
    }

    /**
     * The dotfile case, spelled out because it is the one with the secrets in
     * it. `.env` is a real file, so `-f` covers it; this asserts that nothing
     * has been added above the rule that would let it through first.
     */
    public function testNothingPrecedesTheRefusalThatCouldServeADotfile(): void
    {
        $before = substr(self::directives(), 0, (int) strpos(self::directives(), 'RewriteRule ^ - [F]'));

        self::assertStringNotContainsString('RewriteRule', $before);
        self::assertStringNotContainsString('Allow from', $before);
    }
}
