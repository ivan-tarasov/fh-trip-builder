<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * The rule that makes bearer tokens reach PHP at all.
 *
 * Apache hands `Authorization` to the CGI/FPM process only when told to. On
 * this deployment it was not: `$_SERVER` held no AUTH key and
 * `getallheaders()` answered `Accept`, `User-Agent`, `Host` and nothing else,
 * on a request that carried a token — so every authenticated call to `/api/*`
 * answered 401, for every client, for as long as the endpoints had existed
 * (E28, #207).
 *
 * Structural, because Apache is not in CI. The change that added this was
 * measured against the local vhost, where an authorised call went from 401 to
 * 200; what is left for here is that nobody quietly drops the rule or moves it
 * somewhere it cannot run.
 */
final class AuthorizationHeaderTest extends TestCase
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

    public function testTheHeaderIsCopiedIntoTheEnvironment(): void
    {
        $directives = self::directives();

        self::assertStringContainsString('RewriteCond %{HTTP:Authorization} .', $directives);
        self::assertStringContainsString(
            'RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
            $directives,
        );
    }

    /**
     * Before the front controller, and that is the whole of why it works.
     *
     * The routing rule ends in `[L]`. Anything after it never runs, so this
     * rule placed below would be a rule that is present, looks right, and does
     * nothing at all — which is indistinguishable from the bug it fixes.
     */
    public function testItRunsBeforeTheRuleThatEndsProcessing(): void
    {
        preg_match_all('/^RewriteRule .*/m', self::directives(), $found);

        $copies = self::indexOf($found[0], 'RewriteRule ^ - [E=HTTP_AUTHORIZATION:');
        $routes = self::indexOf($found[0], 'RewriteRule ^ index.php');

        self::assertNotNull($copies, 'the Authorization rule is gone');
        self::assertNotNull($routes, 'the front-controller rule is gone');
        self::assertLessThan(
            $routes,
            $copies,
            'The front controller ends rewrite processing, so a rule below it never runs.',
        );
    }

    /**
     * `CGIPassAuth On` says the same thing in one line and is the better answer
     * where it exists — but it is an Apache 2.4.13+ core directive, so on
     * anything older the whole file is a 500 and the site is down. This host is
     * not ours to make promises about.
     */
    public function testItDoesNotUseADirectiveThatCanTakeTheSiteDown(): void
    {
        self::assertStringNotContainsStringIgnoringCase('CGIPassAuth', self::directives());
    }

    /**
     * @param list<string> $lines
     */
    private static function indexOf(array $lines, string $prefix): ?int
    {
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, $prefix)) {
                return $index;
            }
        }

        return null;
    }
}
