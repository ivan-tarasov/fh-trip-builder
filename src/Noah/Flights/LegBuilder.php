<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;
use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\Helper;

/**
 * Decides which aircraft flies a leg, how long it takes and what it sells.
 *
 * Shared by the two commands that need the answer: `flights:add` when drawing a
 * new leg, and `flights:realign` when correcting one already in the table. The
 * rule lives here once, so a realigned flight is indistinguishable from a
 * freshly generated one -- the arrangement FarePricing uses for the fare.
 *
 * The fleet and its cabins are read once into memory. There are 28 types, so a
 * per-leg query would be 695,000 lookups of the same 28 rows.
 */
final class LegBuilder
{
    /**
     * Longest leg the network schedules as a nonstop, roughly the longest
     * scheduled nonstop in the world.
     *
     * Beyond this a traveller connects, which is what happens in reality. It is
     * also what keeps the generator from inventing legs no aircraft can operate
     * -- the network once held ~12k flights up to 19,940 km with no aircraft
     * assigned at all, because nothing in the fleet could fly them.
     */
    public const int MAX_NONSTOP_KM = 15300;

    /**
     * How sharply a type is favoured for using more of its range. The draw is
     * weighted by utilisation -- the share of the aircraft's usable range the
     * leg actually needs -- raised to this power, so a 500 km hop strongly
     * prefers a turboprop over a frame built to cross an ocean.
     *
     * Replaces a flat "spare range" falloff, which was far too shallow to hold
     * back a long tail: with fourteen widebodies all mildly penalised on a short
     * leg, they collectively outdrew the fourteen narrowbodies and took 30% of
     * short-haul.
     */
    private const int FIT_EXPONENT = 2;

    /**
     * Share of its published range a narrowbody is actually scheduled over.
     *
     * The book figure assumes a light payload; a full single-aisle does not make
     * it, and long-haul narrowbody service is rare in practice. Widebodies are
     * flown much closer to their limit -- an ultra-long-haul route is the whole
     * reason those frames exist -- so they keep the published figure.
     */
    private const float NARROWBODY_RANGE_SHARE = 0.75;

    /**
     * Minutes of taxi, climb and descent on top of the cruise, which the
     * aircraft's cruise speed alone does not account for. This is why a 350 km
     * hop is scheduled at an hour rather than the 25 minutes it cruises for.
     */
    private const array DURATION_ADD_MINUTES = [10, 55];

    /**
     * Only reached when no type could be drawn, which a caller respecting
     * maxLegKm() will never see.
     */
    private const int FALLBACK_CRUISE_KMH = 850;

    /**
     * @param list<array<string, mixed>> $fleet
     * @param array<string, int> $cabinMask cabins bitmask per aircraft code
     */
    private function __construct(
        private array $fleet,
        private array $cabinMask,
    ) {}

    /**
     * Read the fleet and its fitted cabins from the database.
     *
     * @throws RuntimeException when no aircraft are seeded
     */
    public static function fromConnection(Connection $connection): self
    {
        $fleet = $connection->fetchAll(
            'SELECT code, max_range_km, cruise_speed_kmh, is_widebody FROM ' . Table::Aircraft->value
            . ' WHERE max_range_km > 0 ORDER BY max_range_km ASC',
        );

        if ($fleet === []) {
            throw new RuntimeException('No aircraft types are seeded — run app:install first.');
        }

        // What each type has fitted, folded to one mask per type: 28 masks built
        // once, rather than the same fold repeated for every leg.
        $fitted = [];

        foreach ($connection->fetchAll(
            'SELECT aircraft, cabin FROM ' . Table::AircraftCabins->value,
        ) as $row) {
            $fitted[(string) $row['aircraft']][] = (string) $row['cabin'];
        }

        return new self($fleet, array_map(CabinAvailability::bits(...), $fitted));
    }

    /**
     * Longest leg this fleet will be asked to fly, in km.
     *
     * The shorter of what the fleet can reach and what anyone schedules
     * nonstop, so a caller filtering routes by this can rely on assign()
     * always finding a type.
     */
    public function maxLegKm(): int
    {
        return min(
            self::MAX_NONSTOP_KM,
            (int) max(array_map(self::usableRange(...), $this->fleet)),
        );
    }

    /**
     * Draw a type for a leg of this length and derive the rest from it.
     */
    public function assign(int $distance): LegAssignment
    {
        $type = $this->pickType($distance);

        return new LegAssignment(
            aircraft: $type === null ? null : (string) $type['code'],
            duration: $this->duration($distance, $type),
            // No type means no cabin rows to read: a leg nothing in the fleet
            // can fly still sells economy.
            cabins: $type === null
                ? CabinClass::Economy->bit()
                : $this->cabinMask[(string) $type['code']] ?? CabinClass::Economy->bit(),
        );
    }

    /**
     * Arrival time for a leg, as 'Y-m-d H:i'.
     *
     * Crosses timezones properly rather than adding minutes to a local clock:
     * the departure is anchored in its own zone, the duration is added, and the
     * result is read in the arrival zone.
     *
     * @throws Exception when a timezone or the departure stamp will not parse
     */
    public static function arrivalTime(
        string $departure,
        string $departTimezone,
        string $arriveTimezone,
        int $duration,
    ): string {
        try {
            return new DateTimeImmutable($departure, new DateTimeZone($departTimezone))
                ->modify(sprintf('%+d minutes', $duration))
                ->setTimezone(new DateTimeZone($arriveTimezone))
                ->format('Y-m-d H:i');
        } catch (Throwable $e) {
            throw new Exception('Unable to calculate arrival time: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * An aircraft type that could actually fly this leg, or null when none can.
     *
     * Only types whose usable range covers the distance are eligible, and among
     * those the draw is weighted by utilisation: the share of that range the leg
     * needs, raised to FIT_EXPONENT. A type sized for the leg is near 1 and
     * dominates, while one built to cross an ocean is near 0 on a short hop and
     * is drawn rarely rather than merely a little less often.
     *
     * Utilisation is measured against usable range, not the published figure,
     * so the two body types are compared on what each is really flown over.
     *
     * @return array<string, mixed>|null the chosen type's row
     */
    private function pickType(int $distance): ?array
    {
        $types = [];
        $cumulative = [];
        $running = 0.0;

        foreach ($this->fleet as $type) {
            $range = self::usableRange($type);

            // Cannot make the leg. Not a stopping point: the fleet is ordered by
            // published range, which the narrowbody haircut does not preserve.
            if ($range < $distance) {
                continue;
            }

            $running += ($distance / $range) ** self::FIT_EXPONENT;
            $types[] = $type;
            $cumulative[] = $running;
        }

        // Longer than anything in the fleet can fly. A caller that filtered on
        // maxLegKm() cannot get here, so reaching it means the two disagree --
        // report no type rather than inventing one that could not make it.
        if ($types === [] || $running <= 0.0) {
            return null;
        }

        return $types[Helper::pickWeighted($cumulative, $running)];
    }

    /**
     * How long the leg takes, in minutes.
     *
     * The speed is the operating type's own cruise figure rather than a random
     * draw, so a turboprop no longer crosses a leg as fast as a 787: an ATR 72
     * cruises at 510 km/h against the 917 km/h of a 747.
     *
     * @param array<string, mixed>|null $type
     */
    private function duration(int $distance, ?array $type): int
    {
        if ($distance <= 0) {
            return 0;
        }

        $speed = $type === null ? self::FALLBACK_CRUISE_KMH : (int) $type['cruise_speed_kmh'];

        if ($speed <= 0) {
            throw new RuntimeException('Flight speed must be greater than zero.');
        }

        return (int) round($distance / $speed * 60) + Helper::random(self::DURATION_ADD_MINUTES);
    }

    /**
     * The range a type is actually scheduled over, in km.
     *
     * @param array<string, mixed> $type
     */
    private static function usableRange(array $type): int
    {
        $range = (int) $type['max_range_km'];

        return (bool) $type['is_widebody']
            ? $range
            : (int) round($range * self::NARROWBODY_RANGE_SHARE);
    }
}
