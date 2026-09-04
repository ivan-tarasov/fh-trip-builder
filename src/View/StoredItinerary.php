<?php

declare(strict_types=1);

namespace TripBuilder\View;

use stdClass;

/**
 * Rebuilds the itinerary ItineraryPresenter expects from the segments a booking
 * stored at purchase.
 *
 * A booking keeps its whole trip as JSON: one entry per flight leg, written by
 * the same FlightFinder::mapLeg() that feeds a search result. So the segments
 * are already presenter-ready and spelled identically -- what a stored booking
 * lacks is only the four itinerary-level keys the search wraps them in, and
 * every one of those is arithmetic over the segments themselves.
 *
 * That is the whole point of this class: a booking renders through the same
 * cards as a search result without a single query. Its price, cabin and
 * aircraft stay frozen at what was sold, which re-fetching by leg id would
 * quietly undo. Nothing here holds a Connection, and nothing here may.
 */
final class StoredItinerary
{
    /**
     * Null when there is nothing renderable, so a caller can skip the row
     * rather than draw a broken card.
     */
    public static function fromJson(?string $json): ?stdClass
    {
        $segments = json_decode((string) $json, false);

        // One decode to objects, matching how the saved page reaches the
        // presenter. A bare object is a single leg from an older writer.
        if ($segments instanceof stdClass) {
            $segments = [$segments];
        }

        if (!is_array($segments) || $segments === []) {
            return null;
        }

        foreach ($segments as $segment) {
            if (!self::isRenderable($segment)) {
                return null;
            }
        }

        $layovers = self::layovers($segments);
        $duration = array_sum(array_map(static fn(stdClass $s): int => (int) $s->duration, $segments));

        foreach ($layovers as $layover) {
            $duration += $layover->wait_minutes;
        }

        return (object) [
            'segments' => array_values($segments),
            'stops' => count($segments) - 1,
            'total_duration' => $duration,
            'layovers' => $layovers,
            // A search ranking artefact -- "cheapest", "fastest" -- which says
            // nothing about a trip already bought. The presenter maps over it,
            // so it has to be present and empty rather than absent.
            'badges' => [],
        ];
    }

    /**
     * The wait at each intermediate airport, mirroring FlightFinder's own
     * layover pass so a booking and a search result agree on the numbers.
     *
     * @param list<stdClass> $segments
     * @return list<stdClass>
     */
    private static function layovers(array $segments): array
    {
        $layovers = [];

        for ($i = 1; $i < count($segments); $i++) {
            $previous = $segments[$i - 1];

            $layovers[] = (object) [
                'airport_code' => $previous->arrive->airport_code ?? null,
                'airport_name' => $previous->arrive->airport_name ?? null,
                'airport_city' => $previous->arrive->airport_city ?? null,
                // A layover is at one airport, so this subtraction is safe
                // (leg stamps are local, and can't be subtracted across zones).
                'wait_minutes' => (int) round(
                    (strtotime((string) $segments[$i]->depart->date_time)
                        - strtotime((string) $previous->arrive->date_time)) / 60,
                ),
            ];
        }

        return $layovers;
    }

    /**
     * The three fields this class computes from. Everything else the presenter
     * reads it already guards, and carrier and flight number have been written
     * by every version of mapLeg(), so checking them buys nothing.
     */
    private static function isRenderable(mixed $segment): bool
    {
        return $segment instanceof stdClass
            && isset($segment->duration)
            && isset($segment->depart->date_time)
            && isset($segment->arrive->date_time);
    }
}
