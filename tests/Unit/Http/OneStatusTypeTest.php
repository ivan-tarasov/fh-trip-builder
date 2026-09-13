<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Helper;

/**
 * A status code is a type, not a number that happens to be three digits.
 *
 * `HttpStatus` existed and was used only by `src/Api/`, while the rest of the
 * application wrote the literals (E15, #162). Replacing them was the easy half;
 * this is what stops the next one being written, because the signatures alone
 * cannot — `http_response_code()` takes an int and always will.
 *
 * The rule is not "no three-digit numbers". It is that the ones which *mean* a
 * status come from the enum, and the only place an int is allowed is where
 * PHP's own API demands one: `->value`, at the boundary.
 */
final class OneStatusTypeTest extends TestCase
{
    /**
     * Where a status is set, it is set from the enum.
     */
    public function testNoStatusCodeIsWrittenAsANumber(): void
    {
        $offences = [];

        foreach (self::sourceFiles() as $file => $contents) {
            foreach (explode("\n", $contents) as $number => $line) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                if (preg_match('/http_response_code\(\s*\d{3}\s*\)/', $line) === 1) {
                    $offences[] = $file . ':' . ($number + 1);
                }

                if (preg_match('/->bounce\([^;]*,\s*\d{3}\s*\)/', $line) === 1) {
                    $offences[] = $file . ':' . ($number + 1);
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            'A status is being written as a number. Use HttpStatus — and `->value` only where '
            . "PHP's own API takes an int.",
        );
    }

    /**
     * The enum is in `Http`, not `Api`.
     *
     * It moved because a status code is an HTTP concept: under `Api`, every
     * controller naming one depended on the API's namespace, and moving the API
     * out later would have broken `CheckoutController` for a reason unrelated
     * to the API.
     */
    public function testTheEnumLivesWithTheRestOfTheHttpVocabulary(): void
    {
        self::assertFileExists(Helper::getRootDir() . '/src/Http/HttpStatus.php');
        self::assertFileDoesNotExist(Helper::getRootDir() . '/src/Api/HttpStatus.php');
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

        $found['public/index.php'] = (string) file_get_contents(Helper::getPublicDir() . '/index.php');

        return $found;
    }
}
