<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Helper;

/**
 * One place reads the environment.
 *
 * `$_ENV` and `getenv()` do not answer the same question. phpdotenv's
 * immutable loader copies `.env` into `$_ENV` and skips any variable the
 * process environment already holds, so a variable exported by CI or by an
 * Apache `SetEnv` is in one and not the other -- and the code that reads the
 * wrong one gets '' rather than an error.
 *
 * That had been found and fixed three times in three places, each fix local to
 * the thing that broke: the connection grew its own reader, MapView copied it,
 * and the installer stopped asking and used `DATABASE()` instead. `Config` and
 * `Cdn` were never visited, and both were still wrong on 2026-09-13 -- `Cdn`
 * quietly enough that the CI step written to prove `airside:prune` refuses
 * with no distribution configured would have passed with one configured,
 * because none was ever visible to it.
 *
 * Env::get() is now the answer, and this is what keeps a fourth copy from
 * being written.
 */
final class OneEnvReaderTest extends TestCase
{
    private const string READER = 'src/Env.php';

    public function testNothingButEnvReadsTheEnvironment(): void
    {
        $offences = [];

        foreach (self::sourceFiles() as $file => $contents) {
            if ($file === self::READER) {
                continue;
            }

            foreach (self::reads($contents) as $line => $what) {
                $offences[] = $file . ':' . $line . ' reads ' . $what;
            }
        }

        self::assertSame(
            [],
            $offences,
            'Read the environment through Env::get(). $_ENV and getenv() disagree about any '
            . 'variable the host exports, and the one that is wrong returns an empty string '
            . 'rather than failing — see the note in src/Env.php.',
        );
    }

    /**
     * Tokens and not a text search, so the explanations of this rule -- which
     * have to name both spellings -- are not breaches of it.
     *
     * @return array<int, string>
     */
    private static function reads(string $contents): array
    {
        $found = [];

        foreach (token_get_all($contents) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_VARIABLE && $token[1] === '$_ENV') {
                $found[$token[2]] = '$_ENV';
            }

            if ($token[0] === T_STRING && strtolower($token[1]) === 'getenv') {
                $found[$token[2]] = 'getenv()';
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private static function sourceFiles(): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Helper::getRootDir() . '/src'));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[substr($file->getPathname(), strlen(Helper::getRootDir()) + 1)]
                    = (string) file_get_contents($file->getPathname());
            }
        }

        return $found;
    }
}
