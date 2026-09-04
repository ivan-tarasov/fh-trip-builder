<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Repository\FareBrandRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * Fare brands, and the join between a generated leg and the rules it is sold
 * under.
 *
 * The contract that matters at checkout: every leg names a brand the seed
 * knows, so a page can always state what the ticket allows. A leg pointing at a
 * brand that no longer exists would show a booking with no rules at all.
 */
final class FareBrandRepositoryTest extends IntegrationTestCase
{
    public function testTheSeededBrandsRunFromRestrictiveToFlexible(): void
    {
        $brands = new FareBrandRepository($this->connection())->all();

        self::assertNotEmpty($brands, 'No fare brands seeded — run app:install.');

        $refundable = array_filter($brands, static fn($rules): bool => $rules->refundable);
        $noChanges = array_filter($brands, static fn($rules): bool => $rules->changes === 0);

        // Both ends have to exist, or the fare column says nothing: a set where
        // everything refunds is the same as no fare rules at all.
        self::assertNotEmpty($refundable, 'No refundable fare among the brands.');
        self::assertNotEmpty($noChanges, 'No restrictive fare among the brands.');
    }

    public function testEveryBrandStatesEveryRule(): void
    {
        foreach (new FareBrandRepository($this->connection())->all() as $code => $rules) {
            $lines = $rules->lines();

            self::assertNotEmpty($lines, sprintf('%s produced no rules to show', $code));

            foreach ($lines as $line) {
                self::assertNotSame('', trim($line['text']), sprintf('%s has an empty rule line', $code));
            }
        }
    }

    public function testEveryGeneratedLegNamesABrandTheSeedKnows(): void
    {
        $flights = new FlightRepository($this->connection());
        $result = $flights->searchDirection('PAR', 'NYC', '2026-09-15', SortMethod::Price, 0, 10, CabinClass::Economy);

        if ($result['total'] === 0) {
            self::markTestSkipped('No generated flights on this route (run flights:add).');
        }

        $known = array_keys(new FareBrandRepository($this->connection())->all());

        foreach ($result['rows'] as $itinerary) {
            $ids = array_map(static fn(array $leg): int => (int) $leg['id'], $itinerary['legs']);
            $codes = $flights->fareBrandsByIds($ids);

            self::assertCount(count($ids), $codes, 'A leg came back without a fare brand column.');

            foreach ($codes as $code) {
                self::assertContains($code, $known, sprintf('Leg fare brand "%s" is not seeded', (string) $code));
            }
        }
    }

    public function testAJourneysRulesAreTheStrictestOfItsLegs(): void
    {
        $flights = new FlightRepository($this->connection());
        $brands = new FareBrandRepository($this->connection());
        $result = $flights->searchDirection('PAR', 'NYC', '2026-09-15', SortMethod::Price, 0, 40, CabinClass::Economy);

        if ($result['total'] === 0) {
            self::markTestSkipped('No generated flights on this route (run flights:add).');
        }

        $checked = 0;

        foreach ($result['rows'] as $itinerary) {
            if ((int) $itinerary['stops'] === 0) {
                continue;
            }

            $ids = array_map(static fn(array $leg): int => (int) $leg['id'], $itinerary['legs']);
            $codes = $flights->fareBrandsByIds($ids);
            $journey = $brands->rulesFor($codes);

            self::assertNotNull($journey);

            // Whatever the legs allow individually, the journey allows no more.
            foreach ($codes as $code) {
                $leg = $brands->all()[(string) $code];

                self::assertLessThanOrEqual($leg->changes, $journey->changes);
                self::assertLessThanOrEqual($leg->cancellation, $journey->cancellation);
                self::assertLessThanOrEqual($leg->checkedBag, $journey->checkedBag);
                self::assertLessThanOrEqual($leg->carryOn, $journey->carryOn);

                if (!$leg->refundable) {
                    self::assertFalse($journey->refundable);
                }
            }

            $checked++;

            if ($checked === 5) {
                break;
            }
        }

        if ($checked === 0) {
            self::markTestSkipped('No connecting itineraries on this route to fold.');
        }
    }

    public function testAnUnknownBrandLeavesNoRulesRatherThanWrongOnes(): void
    {
        $brands = new FareBrandRepository($this->connection());

        // A leg generated before a brand was removed. Better to show nothing
        // than to fall back on a default that promises the wrong terms.
        self::assertNull($brands->rulesFor([null]));
        self::assertNull($brands->rulesFor(['ZZ']));
        self::assertNull($brands->rulesFor([]));
    }
}
