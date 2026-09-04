<?php

declare(strict_types=1);

namespace TripBuilder\View;

use DateTime;
use TripBuilder\Cdn;
use TripBuilder\Config;

/**
 * Turns an itinerary (an ordered list of flight segments plus its layovers)
 * into the view-model the flight cards render.
 *
 * This lives outside the controllers because two pages draw the same card: the
 * search results and the saved-flights list. Keeping one presenter means a
 * change to how a route bar or a notice reads shows up in both.
 */
class ItineraryPresenter
{
    // What counts as worth warning about on an itinerary.
    /**
     * Cabin codes to names. FlightFinder currently supplies these itself with
     * a FIXME -- 'Y' outbound, 'X' return -- because the flights table has no
     * cabin column yet. 'Y' is a real IATA economy code and resolves; 'X' is
     * not a cabin at all and deliberately resolves to nothing, so a return leg
     * shows no cabin rather than a made-up one. Both start reporting correctly
     * the moment the column exists.
     */
    private const array CABIN_NAMES = [
        'Y' => 'Economy',
        'W' => 'Premium economy',
        'C' => 'Business',
        'J' => 'Business',
        'F' => 'First',
    ];

    private const int LAYOVER_TIGHT_MINUTES = 90;
    private const int LAYOVER_LONG_MINUTES = 300;
    private const int LONG_TRIP_MINUTES = 1440;

    /**
     * @return array{direction: array<string, mixed>, ids: list<int>}
     */
    public function direction(object $itinerary): array
    {
        $segments = $itinerary->segments;
        $first = $segments[0];
        $last = $segments[array_key_last($segments)];

        $ids = [];
        $detail = [];
        $carriers = [];

        foreach ($segments as $segment) {
            $ids[] = (int) $segment->id;

            // One entry per airline, so a codeshare-ish itinerary shows each
            // logo rather than repeating the first.
            $carriers[$segment->carrier] ??= [
                'name' => $segment->carrier_name,
                'logo_url' => $this->carrierLogo($segment->carrier),
            ];

            $detail[] = [
                'carrier_name' => $segment->carrier_name,
                'logo_url' => $this->carrierLogo($segment->carrier),
                'flight_number' => 'Flight ' . str_replace('-', '', $segment->number),
                'duration' => $this->minutesToStringTime($segment->duration),
                'cabin' => self::CABIN_NAMES[$segment->cabin_code ?? ''] ?? null,
                'aircraft' => $segment->aircraft ?? null,
                // What the cabin is like on this frame. Each is null when the
                // data does not reach that far -- an unknown type, or a type
                // with no such cabin on board -- and the template prints only
                // what it has rather than inventing a placeholder. This is the
                // same restraint that removed the hardcoded amenity icons; the
                // difference is that these are real, seeded per aircraft.
                'aircraft_body' => match ($segment->aircraft_widebody ?? null) {
                    true => 'widebody',
                    false => 'narrowbody',
                    default => null,
                },
                'seat_layout' => $segment->seat_layout ?? null,
                // Pitch is held in inches, the trade's unit, and shown in
                // centimetres to sit with the kilometres used everywhere else.
                'seat_pitch' => isset($segment->seat_pitch) && $segment->seat_pitch > 0
                    ? sprintf('%d cm', (int) round($segment->seat_pitch * 2.54))
                    : null,
                'seat_flat_bed' => (bool) ($segment->seat_flat_bed ?? false),
                'depart_time' => date('H:i', strtotime($segment->depart->date_time)),
                'depart_date' => date('D, d M', strtotime($segment->depart->date_time)),
                'depart_city' => $segment->depart->airport_city,
                'depart_code' => $segment->depart->airport_code,
                'arrive_time' => date('H:i', strtotime($segment->arrive->date_time)),
                'arrive_date' => date('D, d M', strtotime($segment->arrive->date_time)),
                'arrive_city' => $segment->arrive->airport_city,
                'arrive_code' => $segment->arrive->airport_code,
            ];
        }

        return [
            'direction' => [
                'stops_label' => $this->stopsLabel((int) $itinerary->stops),
                'duration' => $this->minutesToStringTime((int) $itinerary->total_duration),
                'carriers' => array_values($carriers),
                'depart_time' => date('H:i', strtotime($first->depart->date_time)),
                'depart_code' => $first->depart->airport_code,
                'depart_city' => $first->depart->airport_city,
                'depart_day' => date('D, j M', strtotime($first->depart->date_time)),
                'arrive_time' => date('H:i', strtotime($last->arrive->date_time)),
                'arrive_code' => $last->arrive->airport_code,
                'arrive_city' => $last->arrive->airport_city,
                'arrive_day' => date('D, j M', strtotime($last->arrive->date_time)),
                'notices' => $this->buildNotices($segments, $itinerary->layovers, (int) $itinerary->total_duration),
                'badges' => array_map($this->badgeMeta(...), $itinerary->badges),
                'route' => $this->routeParts($itinerary),
                'layovers' => $this->layovers($itinerary),
                'segments' => $detail,
            ],
            'ids' => $ids,
        ];
    }

    /**
     * The route bar: one part per flight leg and one per layover, each weighted
     * by its own minutes so the drawn widths show the real shape of the
     * journey. Airport codes sit under the part they belong to.
     *
     * @return list<array<string, mixed>>
     */
    private function routeParts(object $itinerary): array
    {
        $segments = $itinerary->segments;
        $parts = [];
        $lastSegment = array_key_last($segments);

        foreach ($segments as $i => $segment) {
            $parts[] = [
                'type' => 'leg',
                'weight' => max(1, (int) $segment->duration),
                'tooltip' => sprintf(
                    '%s in the air · %s–%s',
                    $this->minutesToStringTime((int) $segment->duration),
                    $segment->depart->airport_code,
                    $segment->arrive->airport_code,
                ),
                'start_code' => $i === 0 ? $segment->depart->airport_code : null,
                'end_code' => $i === $lastSegment ? $segment->arrive->airport_code : null,
                'code' => null,
            ];

            if (isset($itinerary->layovers[$i])) {
                $layover = $itinerary->layovers[$i];

                $parts[] = [
                    'type' => 'stop',
                    'weight' => max(1, (int) $layover->wait_minutes),
                    'tooltip' => sprintf(
                        'Layover at %s (%s) — %s',
                        $layover->airport_name,
                        $layover->airport_city,
                        $this->minutesToStringTime((int) $layover->wait_minutes),
                    ),
                    'start_code' => null,
                    'end_code' => null,
                    'code' => $layover->airport_code,
                ];
            }
        }

        return $parts;
    }

    /**
     * @return list<array<string, string>>
     */
    private function layovers(object $itinerary): array
    {
        $layovers = [];

        foreach ($itinerary->layovers as $layover) {
            $layovers[] = [
                'airport_code' => $layover->airport_code,
                'airport_city' => $layover->airport_city,
                'wait' => $this->minutesToStringTime($layover->wait_minutes),
            ];
        }

        return $layovers;
    }

    public function carrierLogo(string $carrier): string
    {
        return Cdn::getUrl(sprintf(
            '%s/suppliers/%s.png',
            Config::get('site.static.endpoint.images'),
            $carrier,
        ));
    }

    /**
     * Things about an itinerary a traveller would want to spot before choosing:
     * connections that are tight or long, layovers spent overnight, a transit
     * country that may need a visa, separately-ticketed airlines, and very long
     * journeys. Each notice is deduplicated by label so a two-stop trip doesn't
     * repeat the same warning.
     *
     * @param list<object> $segments
     * @param list<object> $layovers
     * @return list<array<string, string>>
     */
    /**
     * Three severities, because these six things are not equally serious and
     * rendering them identically made the reader weigh them equally:
     *
     *   risk   something can go wrong on the day  -- a connection too tight
     *   check  something may be needed before you fly -- a transit visa, or
     *          bags to collect and re-check between two airlines
     *   note   a fact about the itinerary -- how long the wait, how long the
     *          trip, whether the wait runs overnight
     *
     * A 29-hour journey is not a warning; a 45-minute connection in a foreign
     * airport is. The colour now says which is which.
     *
     * @param list<object> $segments
     * @param list<object> $layovers
     * @return list<array<string, string>>
     */
    private function buildNotices(array $segments, array $layovers, int $totalDuration): array
    {
        $notices = [];
        $originCountry = $segments[0]->depart->airport_country;
        $destinationCountry = $segments[array_key_last($segments)]->arrive->airport_country;

        foreach ($layovers as $i => $layover) {
            $wait = (int) $layover->wait_minutes;
            $city = $layover->airport_city;
            $waitLabel = $this->minutesToStringTime($wait);

            if ($wait < self::LAYOVER_TIGHT_MINUTES) {
                $notices['tight'] = [
                    'severity' => 'risk',
                    'icon' => 'person-running',
                    'label' => 'Tight connection',
                    'text' => sprintf('Only %s to change planes in %s', $waitLabel, $city),
                ];
            } elseif ($wait > self::LAYOVER_LONG_MINUTES) {
                $notices['long'] = [
                    'severity' => 'note',
                    'icon' => 'hourglass-half',
                    'label' => 'Long layover',
                    'text' => sprintf('%s waiting in %s', $waitLabel, $city),
                ];
            }

            if (isset($segments[$i], $segments[$i + 1]) && $this->spansNight(
                $segments[$i]->arrive->date_time,
                $segments[$i + 1]->depart->date_time,
            )) {
                $notices['night'] = [
                    'severity' => 'note',
                    'icon' => 'moon',
                    'label' => 'Night layover',
                    'text' => sprintf('The wait in %s runs through the night', $city),
                ];
            }

            $country = $segments[$i]->arrive->airport_country;

            if ($country !== $originCountry && $country !== $destinationCountry) {
                $notices['visa'] = [
                    'severity' => 'check',
                    'icon' => 'passport',
                    'label' => 'Transit visa',
                    'text' => sprintf('Connects through %s — check whether a transit visa is needed', $country),
                ];
            }
        }

        $carriers = array_unique(array_map(static fn(object $s): string => $s->carrier, $segments));

        if (count($carriers) > 1) {
            $notices['airlines'] = [
                'severity' => 'check',
                'icon' => 'suitcase-rolling',
                'label' => 'Separate airlines',
                'text' => 'Flights are on different airlines — bags may need collecting and re-checking',
            ];
        }

        if ($totalDuration > self::LONG_TRIP_MINUTES) {
            $notices['duration'] = [
                'severity' => 'note',
                'icon' => 'clock',
                'label' => 'Long journey',
                'text' => sprintf('%s door to door', $this->minutesToStringTime($totalDuration)),
            ];
        }

        return array_values($notices);
    }

    /**
     * Whether a window overlaps the small hours on any night it touches.
     */
    private function spansNight(string $from, string $to): bool
    {
        $start = strtotime($from);
        $end = strtotime($to);
        // Shared with the "no night layovers" filter, so the notice and the
        // filter cannot drift apart.
        $fromHour = (int) Config::get('search.filters.night_from_hour', 23);
        $toHour = (int) Config::get('search.filters.night_to_hour', 6);

        for ($day = strtotime('midnight', $start) - 86400; $day <= $end; $day += 86400) {
            $nightStart = $day + $fromHour * 3600;
            $nightEnd = $day + 86400 + $toHour * 3600;

            if ($start < $nightEnd && $end > $nightStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Presentation for a badge slug decided by the repository.
     *
     * @return array<string, string>
     */
    public function badgeMeta(string $slug): array
    {
        return match ($slug) {
            'cheapest' => ['label' => 'Cheapest', 'tone' => 'success', 'icon' => 'tag',
                'text' => 'Lowest price of every option we found'],
            'fastest' => ['label' => 'Fastest', 'tone' => 'purple', 'icon' => 'bolt',
                'text' => 'Shortest door-to-door time of every option we found'],
            'value' => ['label' => 'Best value', 'tone' => 'primary', 'icon' => 'thumbs-up',
                'text' => 'The best balance of price and travel time'],
            'nonstop' => ['label' => 'Cheapest nonstop', 'tone' => 'teal', 'icon' => 'plane',
                'text' => 'Lowest price among the flights with no connection'],
            default => ['label' => ucfirst($slug), 'tone' => 'secondary', 'icon' => 'star', 'text' => ''],
        };
    }

    /**
     * Split a price so the template can size the parts differently: the whole
     * amount is what people scan, the cents are detail.
     *
     * @return array{whole: string, cents: string}
     */
    public function priceParts(float $amount): array
    {
        [$whole, $cents] = explode('.', number_format($amount, 2, '.', ','));

        return ['whole' => $whole, 'cents' => $cents];
    }

    public function stopsLabel(int $stops): string
    {
        return match (true) {
            $stops === 0 => 'Direct',
            $stops === 1 => '1 stop',
            default => $stops . ' stops',
        };
    }

    public function minutesToStringTime(int $minutes): string
    {
        $seconds = $minutes * 60;

        $dtF = new DateTime('@0');
        $dtT = new DateTime("@$seconds");

        $interval = $dtF->diff($dtT);

        $timeComponents = [
            'd' => $interval->format('%a'),
            'h' => $interval->format('%h'),
            'm' => $interval->format('%i'),
        ];

        $formattedTime = '';

        foreach ($timeComponents as $unit => $value) {
            if ($value != 0) {
                $formattedTime .= $value . $unit . ' ';
            }
        }

        return trim($formattedTime);
    }
}
