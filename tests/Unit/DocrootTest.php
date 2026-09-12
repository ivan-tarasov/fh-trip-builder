<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * The document root is public/, and public/ holds only pages.
 *
 * Apache is not in CI, so nothing here can make a request and read a status
 * code. What can be checked is the shape the deploy copies into place and the
 * shape of the directory it points at. A structural test, and a weaker one
 * than a request -- which is why the pull request that changed this also
 * carries the local and production probes.
 *
 * It replaces the allow-list test from E7.1 (#139). That one asserted a rule
 * held the repository root shut; there is no longer anything to hold shut,
 * because the server is pointed at a directory the repository is not in.
 *
 * It is here because the alternative was remembering. Production served
 * composer.lock and executed files under src/ for as long as nobody happened
 * to type the URL (E7, #138), and a later edit could put a file back inside
 * the document root without anything noticing.
 */
final class DocrootTest extends TestCase
{
    /** The lines that matter, with comments and blank lines removed. */
    private static function directives(string $file): string
    {
        $contents = (string) file_get_contents(Helper::getRootDir() . $file);

        return implode("\n", array_filter(
            array_map(trim(...), explode("\n", $contents)),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }

    public function testTheFrontControllerLivesInTheDocroot(): void
    {
        self::assertFileExists(Helper::getPublicDir() . '/index.php');
        self::assertFileDoesNotExist(Helper::getRootDir() . '/index.php');
    }

    /**
     * Every top-level entry in the document root, and nothing else.
     *
     * A file added here is addressable the moment it is pushed, which is the
     * whole of E7 in one sentence. This is the place that notices.
     */
    public function testTheDocrootHoldsOnlyPages(): void
    {
        $found = array_values(array_filter(
            (array) scandir(Helper::getPublicDir()),
            static fn(string $entry): bool => !str_starts_with($entry, '.'),
        ));

        sort($found);

        self::assertSame(
            ['css', 'fonts', 'img', 'index.php', 'js'],
            $found,
            'Something new is in the document root, and is therefore a URL. '
            . 'If that is on purpose, change this test in the same commit.',
        );
    }

    /**
     * The root file refuses everything, with no exceptions at all.
     *
     * It is only ever read when the document root is wrong, and in that case
     * the site should stop rather than serve the repository. An exception
     * added here would be a hole in the one file whose job is not to have one,
     * so the assertion is on the whole rule set and not on one line of it.
     */
    public function testTheProjectRootRefusesEverything(): void
    {
        self::assertSame(
            "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ - [F]\n</IfModule>",
            self::directives('/.htaccess.example'),
        );
    }

    public function testTheDocrootRoutesThroughTheFrontController(): void
    {
        $directives = self::directives('/public/.htaccess.example');

        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $directives);
        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-d', $directives);
        self::assertStringContainsString('RewriteRule ^ index.php [QSA,L]', $directives);
    }

    /**
     * Dotfiles are refused in the document root, and `.well-known` is not.
     *
     * The order is the test. AutoSSL and Let's Encrypt write their challenge
     * files to `.well-known` in whichever directory is the document root, so a
     * dotfile rule that reaches them renews no certificate -- and says so
     * ninety days later, by which time nobody is looking at this file.
     */
    public function testTheDocrootRefusesDotfilesButNotWellKnown(): void
    {
        $directives = self::directives('/public/.htaccess.example');

        $exemption = strpos($directives, 'RewriteCond %{REQUEST_URI} !^/\.well-known/');
        $refusal = strpos($directives, 'RewriteRule (^|/)\. - [F]');

        self::assertIsInt($exemption, 'The .well-known exemption is gone; the certificate will not renew.');
        self::assertIsInt($refusal);
        self::assertLessThan($refusal, $exemption);
    }
}
