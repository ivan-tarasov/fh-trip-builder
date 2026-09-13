<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * The generator writes while it generates.
 *
 * It used to collect every flight and insert the lot at the end, which is
 * 1.06 MB per thousand — 34 MB at ten thousand, and past PHP's default 128 MB
 * somewhere between ninety and a hundred thousand. `flights:add 200000` is
 * what CI runs and what E24.3 (#193) would quadruple, and the failure was a
 * fatal in the middle of the loop that reported nothing (E19, #178).
 *
 * Structural, because the thing worth asserting is a memory profile and a test
 * that measures one is a test that fails on somebody else's laptop. What can
 * be checked is the shape that produced it: an insert inside the loop rather
 * than after it. Collecting first is the natural way to write this, which is
 * exactly why it wants a guard.
 */
final class GeneratorStreamsTest extends TestCase
{
    private const string GENERATOR = '/src/Noah/Flights/Generate.php';

    public function testTheInsertHappensInsideTheGenerationLoop(): void
    {
        $lines = self::source();

        $loop = self::lineOf($lines, 'while ($this->count[self::COUNT_TOTAL] < $flightsToAdd)');
        $insert = self::lineOf($lines, '$this->insertFlights($batch);');
        $finish = self::lineOf($lines, '$progressBar->finish();');

        self::assertNotNull($loop, 'the generation loop has moved; this guard moves with it');
        self::assertNotNull($insert, 'nothing inserts a batch any more');
        self::assertNotNull($finish, 'the progress bar has moved; this guard moves with it');

        self::assertGreaterThan($loop, $insert, 'the first insert should be inside the loop, not before it');
        self::assertLessThan(
            $finish,
            $insert,
            'Every generated flight is being held until the run ends. That is 1.06 MB per thousand '
            . 'and a fatal at PHP\'s default limit somewhere past ninety thousand (E19, #178).',
        );
    }

    /**
     * And nothing accumulates beside it.
     *
     * The batch is bounded by `INSERT_BATCH_SIZE` and emptied on every flush;
     * a second collection growing alongside it would put the old profile back
     * while this guard still passed.
     */
    public function testTheBatchIsEmptiedWhenItIsFlushed(): void
    {
        $lines = self::source();

        self::assertNotNull(
            self::lineOf($lines, 'count($batch) >= self::INSERT_BATCH_SIZE'),
            'the flush is no longer bounded by the batch size',
        );
        self::assertNotNull(
            self::lineOf($lines, '$batch = [];'),
            'the batch is never emptied, so it is not a batch',
        );
    }

    /** @return list<string> */
    private static function source(): array
    {
        $path = Helper::getRootDir() . self::GENERATOR;

        self::assertFileExists($path);

        return explode("\n", (string) file_get_contents($path));
    }

    /**
     * @param list<string> $lines
     */
    private static function lineOf(array $lines, string $needle): ?int
    {
        foreach ($lines as $number => $line) {
            $trimmed = ltrim($line);

            if (!str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '*')
                && str_contains($line, $needle)) {
                return $number + 1;
            }
        }

        return null;
    }
}
