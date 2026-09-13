<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * Every aircraft type has a fuel burn, and every burn is believable.
 *
 * A type seeded without one does not break anything -- `Emissions` returns null
 * and the card prints nothing -- which is exactly why this test exists: adding
 * a type and forgetting the figure would quietly take the CO2 line off every
 * itinerary that flies it, and nothing else would say so (C5, #154).
 */
final class AircraftFuelBurnSeedTest extends TestCase
{
    // An ATR 72 burns about 0.9 kg/km and an A380 about 12.4. Nothing in
    // civil aviation sits outside this, so a value that does is a typo.
    private const float LEAST = 0.5;
    private const float MOST = 20.0;

    public function testEveryTypeHasABurnFigure(): void
    {
        foreach (self::seeded() as $code => $burn) {
            self::assertGreaterThan(0, $burn, $code . ' has no fuel burn seeded');
        }
    }

    public function testEveryBurnIsInRange(): void
    {
        foreach (self::seeded() as $code => $burn) {
            self::assertGreaterThanOrEqual(self::LEAST, $burn, $code . ' burns impossibly little');
            self::assertLessThanOrEqual(self::MOST, $burn, $code . ' burns impossibly much');
        }
    }

    /**
     * Bigger aeroplanes burn more, which is the one ordering worth pinning.
     */
    public function testWidebodiesBurnMoreThanNarrowbodies(): void
    {
        $rows = self::rows();
        $wide = [];
        $narrow = [];

        foreach ($rows as $row) {
            $burn = (float) $row['fuel_burn_kg_per_km'];

            if ($row['is_widebody'] === '1') {
                $wide[] = $burn;
            } else {
                $narrow[] = $burn;
            }
        }

        self::assertNotEmpty($wide);
        self::assertNotEmpty($narrow);
        self::assertGreaterThan(max($narrow), min($wide));
    }

    /** @return array<string, float> */
    private static function seeded(): array
    {
        $burns = [];

        foreach (self::rows() as $row) {
            $burns[$row['code']] = (float) $row['fuel_burn_kg_per_km'];
        }

        return $burns;
    }

    /** @return list<array<string, string>> */
    private static function rows(): array
    {
        $path = Helper::getRootDir() . '/config/noah/db/seeders/aircraft.csv';
        $handle = fopen($path, 'r');

        self::assertNotFalse($handle, 'Could not read the aircraft seed.');

        $columns = fgetcsv($handle, null, ',', '"', '');

        self::assertIsArray($columns);
        self::assertContains('fuel_burn_kg_per_km', $columns, 'The seed has no fuel burn column.');

        $rows = [];

        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            /** @var list<string> $line */
            $rows[] = array_combine(array_map(strval(...), $columns), $line);
        }

        fclose($handle);

        self::assertNotEmpty($rows);

        return $rows;
    }
}
