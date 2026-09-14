<?php

declare(strict_types=1);

namespace TripBuilder\View;

use TripBuilder\Service\FlightFinder;

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
 *
 * Decoded to arrays, not objects -- ItineraryPresenter and friends take the
 * same `ResponseItinerary`/`ResponseSegment` array shapes `FlightFinder`
 * produces, not a second `stdClass` shape only this class ever built.
 *
 * @phpstan-import-type ResponseAirport from FlightFinder
 * @phpstan-import-type ResponseSegment from FlightFinder
 * @phpstan-import-type ResponseLayover from FlightFinder
 * @phpstan-import-type ResponseItinerary from FlightFinder
 */
final class StoredItinerary
{
    /**
     * Null when there is nothing renderable, so a caller can skip the row
     * rather than draw a broken card.
     *
     * @return ResponseItinerary|null
     */
    public static function fromJson(?string $json): ?array
    {
        $segments = json_decode((string) $json, true);

        // A bare map is a single leg from an older writer.
        if (is_array($segments) && array_is_list($segments) === false) {
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

        // Every one passed isRenderable() above, which is what makes them
        // ResponseSegment; array_values, because the shape below is a list.
        /** @var list<ResponseSegment> $segments */
        $segments = array_values($segments);

        $layovers = self::layovers($segments);
        $duration = array_sum(array_map(static fn(array $s): int => $s['duration'], $segments));

        foreach ($layovers as $layover) {
            $duration += $layover['wait_minutes'];
        }

        return [
            'segments' => $segments,
            'stops' => count($segments) - 1,
            'total_duration' => $duration,
            'layovers' => $layovers,
            // A search ranking artefact -- "cheapest", "fastest" -- which says
            // nothing about a trip already bought. The presenter maps over it,
            // so it has to be present and empty rather than absent.
            'badges' => [],
            // Neither figure is known for a stored booking -- it is rebuilt
            // from what was sold rather than from a search, and a search's
            // ranking artefact has nothing to say about a trip already
            // bought. See ItineraryPresenter::emissions().
            'co2_kg' => null,
            'co2_typical' => null,
        ];
    }

    /**
     * The wait at each intermediate airport, mirroring FlightFinder's own
     * layover pass so a booking and a search result agree on the numbers.
     *
     * @param list<ResponseSegment> $segments
     * @return list<ResponseLayover>
     */
    private static function layovers(array $segments): array
    {
        $layovers = [];

        for ($i = 1; $i < count($segments); $i++) {
            $previous = $segments[$i - 1];

            $layovers[] = [
                'airport_code' => $previous['arrive']['airport_code'],
                'airport_name' => $previous['arrive']['airport_name'],
                'airport_city' => $previous['arrive']['airport_city'],
                // A layover is at one airport, so this subtraction is safe
                // (leg stamps are local, and can't be subtracted across zones).
                'wait_minutes' => (int) round(
                    (strtotime($segments[$i]['depart']['date_time'])
                        - strtotime($previous['arrive']['date_time'])) / 60,
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
        return is_array($segment)
            && isset($segment['duration'])
            && is_array($segment['depart'] ?? null)
            && isset($segment['depart']['date_time'])
            && is_array($segment['arrive'] ?? null)
            && isset($segment['arrive']['date_time']);
    }
}
