<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Helper;

/**
 * A wall clock is never compared against an instant.
 *
 * `flights.departure_time` and `arrival_time` are local at their airport --
 * 08:00 at YUL is 08:00 there, which is what a ticket says and what the page
 * prints. `NOW()` is an instant. Comparing them was out by up to thirteen
 * hours, in both directions, for as long as anybody had been looking
 * (E20, #180).
 *
 * E20's own answer to "how do you stop the twelfth occurrence" was to rename
 * the columns so the frame is in the name. That is 170 mentions across 35
 * files including index names, and is still worth doing. This is the cheaper
 * half of the same idea: the mistake is a shape, and a shape can be looked for.
 */
final class OneFrameTest extends TestCase
{
    /** The columns that hold a wall-clock reading rather than an instant. */
    private const string LOCAL_COLUMNS = 'departure_time|arrival_time';

    /**
     * Every way SQL spells "now".
     *
     * `NOW()` alone was the original list, and the sweep that E24.2 (#192)
     * fixed did not use any of them -- it compared against a bound parameter.
     * The others are here so that at least the SQL-side spellings are all
     * closed; see the note on the sweep below for the half a regex cannot see.
     */
    private const string SQL_NOW = 'NOW\(\)|CURDATE\(\)|CURRENT_DATE|CURRENT_TIMESTAMP|UTC_DATE\(\)|UTC_TIMESTAMP\(\)';

    public function testNoLocalTimeIsComparedAgainstAnInstant(): void
    {
        $offences = [];

        foreach (self::sourceFiles() as $file => $contents) {
            foreach (self::statements($contents) as $line => $statement) {
                if (preg_match('/(' . self::LOCAL_COLUMNS . ')\s*(>=|<=|>|<)\s*(' . self::SQL_NOW . ')/', $statement) === 1) {
                    $offences[] = $file . ':' . $line;
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            'A local time is being compared against NOW(). Use `departure_utc`, which is the same '
            . 'moment as an instant — see the column comment in config/noah/db/tables/flights.php.',
        );
    }

    /**
     * `departure_utc` cannot drift, because nothing moves the time beside it.
     *
     * The pair is written together when a flight is generated and never again.
     * `flights:realign` changes the arrival, the aircraft and the duration; if
     * something later starts changing the departure too, it has to change both
     * or the site goes back to answering the wrong question with a fast index.
     */
    public function testNothingMovesTheLocalDepartureAfterItIsWritten(): void
    {
        $offences = [];

        foreach (self::sourceFiles() as $file => $contents) {
            foreach (self::statements($contents) as $line => $statement) {
                if (preg_match('/\bSET\b[^;]*\bdeparture_time\s*=/', $statement) === 1) {
                    $offences[] = $file . ':' . $line;
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            'Something updates `departure_time`. `departure_utc` must move with it, or the two '
            . 'silently disagree and every "has it left yet" answer goes stale.',
        );
    }

    /**
     * Whatever deletes flights asks `departure_utc`, wherever it lives.
     *
     * E24.2 (#192) asked for a general rule -- flag a local column in any
     * comparison, rather than only against `NOW()`. That rule cannot be
     * written, and the reason is worth writing down instead. Search compares
     * `departure_time < ?` against a date the visitor typed, which is *correct*
     * precisely because both sides are local; the sweep compared
     * `departure_time < ?` against a UTC date, which was wrong. The two are the
     * same characters, and twenty legitimate comparisons across four
     * repositories look exactly like the one bug.
     *
     * So this guards the specific question instead: "has this flight gone" is
     * asked of the UTC column or it is asked wrongly. It follows the statement
     * rather than naming a file, because the first version of this named
     * `Cleaning.php` and broke the moment the SQL moved to a repository -- a
     * guard that has to be edited when the code is refactored is a guard that
     * will be deleted instead.
     */
    public function testWhateverDeletesFlightsDecidesInUtc(): void
    {
        $offences = [];
        $found = 0;

        foreach (self::sourceFiles() as $file => $contents) {
            foreach (self::deletesFromFlights($contents) as $line => $statement) {
                $found++;

                if (!str_contains($statement, 'departure_utc')) {
                    $offences[] = $file . ':' . $line;
                }
            }
        }

        self::assertGreaterThan(0, $found, 'nothing deletes a flight any more; this guard is stale');
        self::assertSame(
            [],
            $offences,
            'A wall-clock reading at the departure airport says nothing about whether the flight '
            . 'has left: at -11.00 it is eleven hours out, and the sweep deleted flights nine '
            . 'hours before takeoff (E24.2, #192).',
        );
    }

    /**
     * Each `DELETE FROM flights`, with the clause that follows it.
     *
     * The SQL is concatenated across lines, so a line-at-a-time reader sees the
     * verb and the predicate separately and can judge neither.
     *
     * @return array<int, string>
     */
    private static function deletesFromFlights(string $contents): array
    {
        $statements = [];
        $needle = "'DELETE FROM ' . Table::Flights->value";
        $offset = 0;

        while (($at = strpos($contents, $needle, $offset)) !== false) {
            $statements[substr_count($contents, "\n", 0, $at) + 1] = substr($contents, $at, 300);
            $offset = $at + 1;
        }

        return $statements;
    }

    /**
     * Comments explain the rule; they are not breaches of it.
     *
     * @return array<int, string>
     */
    private static function statements(string $contents): array
    {
        $lines = [];

        foreach (explode("\n", $contents) as $number => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '|')) {
                continue;
            }

            $lines[$number + 1] = $line;
        }

        return $lines;
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
