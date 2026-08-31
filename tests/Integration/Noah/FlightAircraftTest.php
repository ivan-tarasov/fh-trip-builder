<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Noah;

use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The generator may only put an aircraft on a leg that aircraft could fly.
 *
 * This is a property of the generated data rather than of one function, and it
 * is the assumption the "Aircraft models" filter rests on: a search for A320
 * flights should not turn up a transatlantic crossing.
 */
final class FlightAircraftTest extends IntegrationTestCase
{
    public function testSeededFleetCoversShortAndLongHaul(): void
    {
        $fleet = $this->connection()->fetchAll('SELECT code, max_range_km, is_widebody FROM aircraft');

        self::assertNotEmpty($fleet, 'No aircraft seeded — run app:install.');

        $ranges = array_map(static fn(array $t): int => (int) $t['max_range_km'], $fleet);
        $widebodies = array_filter($fleet, static fn(array $t): bool => (int) $t['is_widebody'] === 1);

        // Regional legs and transatlantic legs both have to be flyable, or the
        // generator leaves whole distance bands without an aircraft.
        self::assertLessThan(3000, min($ranges));
        self::assertGreaterThan(12000, max($ranges));
        self::assertNotEmpty($widebodies);
    }

    public function testNoFlightIsOperatedByAnAircraftThatCannotReach(): void
    {
        $violations = (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM flights f'
            . ' INNER JOIN aircraft a ON a.code = f.aircraft'
            . ' WHERE f.distance > a.max_range_km',
        );

        self::assertSame(0, $violations);
    }

    public function testOnlyLegsBeyondEveryAircraftAreLeftUnassigned(): void
    {
        $assigned = (int) $this->connection()->fetchValue('SELECT COUNT(*) FROM flights WHERE aircraft IS NOT NULL');

        if ($assigned === 0) {
            self::markTestSkipped('No generated flights to check (run flights:add).');
        }

        $longestRange = (int) $this->connection()->fetchValue('SELECT MAX(max_range_km) FROM aircraft');

        // An unassigned leg is only defensible when nothing in the fleet could
        // fly it; anything shorter means the chooser skipped a valid type.
        $wronglyUnassigned = (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM flights WHERE aircraft IS NULL AND distance <= ?',
            [$longestRange],
        );

        self::assertSame(0, $wronglyUnassigned);
    }
}
