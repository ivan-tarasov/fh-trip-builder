<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\EnvKey;
use TripBuilder\Helper;

/**
 * `.env.sample` and `EnvKey` answer to each other, in both directions.
 *
 * The sample is the only instruction a new developer or a new server gets, and
 * until E10.4 (#196) nothing compared it with what the code reads. It had
 * drifted both ways at once: fifteen settings listed, thirteen read, and the
 * two extras -- `APP_NAME` and `DB_CONNECTION` -- had been copied into every
 * `.env` on every machine and into the CI workflow, where they were set on
 * faith and used by nothing.
 *
 * Forwards catches the setting somebody adds and forgets to document, which
 * fails at runtime on the one machine that has not got it. Backwards catches
 * the setting nothing reads any more, which never fails at all -- it just
 * stays there looking required.
 */
final class EnvSampleTest extends TestCase
{
    private const string SAMPLE = '.env.sample';

    public function testEverySettingTheCodeReadsIsInTheSample(): void
    {
        $missing = array_values(array_diff(
            array_map(static fn(EnvKey $key): string => $key->value, EnvKey::cases()),
            self::sampleKeys(),
        ));

        self::assertSame(
            [],
            $missing,
            'Read by the code and absent from ' . self::SAMPLE . ', so nobody setting this '
            . 'application up is told to set it.',
        );
    }

    public function testTheSampleAsksForNothingTheCodeDoesNotRead(): void
    {
        $dead = array_values(array_diff(
            self::sampleKeys(),
            array_map(static fn(EnvKey $key): string => $key->value, EnvKey::cases()),
        ));

        self::assertSame(
            [],
            $dead,
            'In ' . self::SAMPLE . ' and read by nothing. Remove it, or add the EnvKey case '
            . 'that reads it — a setting nobody reads is one more thing to get wrong on a server.',
        );
    }

    /**
     * The names only. Comments and blank lines are the file's own structure,
     * and the values are examples rather than settings.
     *
     * @return list<string>
     */
    private static function sampleKeys(): array
    {
        $path = Helper::getRootDir() . '/' . self::SAMPLE;

        self::assertFileExists($path, self::SAMPLE . ' is the install instructions; it cannot be missing.');

        $keys = [];

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $found) === 1) {
                $keys[] = $found[1];
            }
        }

        return $keys;
    }
}
