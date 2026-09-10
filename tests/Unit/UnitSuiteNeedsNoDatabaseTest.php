<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The unit suite runs with no database, and this is what keeps it that way.
 *
 * It did not, and the way it failed is the reason this file exists. One test
 * in here drove a controller, and a controller finds its own connection
 * through Connection::fromEnv() rather than through the integration suite's
 * guard -- so on a machine with no database three unit tests failed, for a
 * reason that had nothing to do with the code under test. They passed in CI,
 * where a database is configured, so the split went unnoticed: the suite was
 * quietly a function of the environment it ran in.
 *
 * The rule is worth holding because of what it buys: `composer test:unit` on a
 * fresh clone, before anything is installed or configured, either passes or
 * has found a real defect. There is no third answer to reason about.
 *
 * Anything that genuinely needs rows belongs in tests/Integration, where the
 * base class turns a missing database into a skip -- and where
 * `composer test:integration` refuses to be satisfied by skips at all.
 */
final class UnitSuiteNeedsNoDatabaseTest extends TestCase
{
    /**
     * Ways a unit test ends up needing a database, all of them indirect.
     *
     * Constructing a controller is the one that actually happened.
     * AbstractController::connection() reaches for Connection::fromEnv() the
     * first time anything asks, and rendering a page asks -- the footer counts
     * rows. The other two are the direct forms, listed because a unit test
     * that wanted rows would reach for one of them next.
     *
     * @var array<string, string>
     */
    private const array FORBIDDEN = [
        '/new [A-Z][A-Za-z]*Controller\s*\(/' => 'constructs a controller, which finds its own connection',
        '/Connection::fromEnv\s*\(/' => 'opens the application\'s own connection',
        '/extends IntegrationTestCase/' => 'extends the integration base class but lives in tests/Unit',
    ];

    public function testNoUnitTestReachesForADatabase(): void
    {
        $offences = [];

        foreach ($this->unitTests() as $path => $source) {
            // Comments stripped first, for the reason PromisesTest strips
            // them: this file and the notes in others have to be allowed to
            // name the thing they are forbidding.
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

            foreach (self::FORBIDDEN as $pattern => $why) {
                if (preg_match($pattern, $code) === 1) {
                    $offences[] = $path . ' ' . $why;
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            'a unit test that needs rows belongs in tests/Integration',
        );
    }

    /**
     * Every test file under tests/Unit, keyed by its path below that.
     *
     * @return array<string, string>
     */
    private function unitTests(): array
    {
        $root = __DIR__;
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            // Not itself. The patterns above are string literals rather than
            // comments, so stripping comments does not hide them and this file
            // matches every rule it carries -- which is the same trap as a
            // `\bhero\b` guard matching `article--hero`.
            if ($file->getFilename() === basename(__FILE__)) {
                continue;
            }

            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $found[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents(
                    $file->getPathname(),
                );
            }
        }

        self::assertNotEmpty($found, 'no unit tests found to check');
        ksort($found);

        return $found;
    }
}
