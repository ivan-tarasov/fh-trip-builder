<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use stdClass;
use TripBuilder\Helper;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Service\FlightFinder;
use TripBuilder\View\ItineraryPresenter;
use TripBuilder\View\TwigRenderer;

class MyController extends AbstractController
{
    // Written by the browser; global.js owns the other half of this contract.
    private const string SAVED_COOKIE = 'tb_saved_flights';
    private const int SAVED_LIMIT = 50;

    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function bookings(): void
    {
        $rows = new BookingRepository($this->connection())->forSession(session_id());

        $bookings = [];

        foreach ($rows as $row) {
            $outbound = json_decode($row['flight_outbound'] ?? '', true);
            $return = json_decode($row['flight_return'] ?? '', true);

            // Skip rows whose stored flight JSON is corrupt or empty.
            if (!is_array($outbound) || $outbound === []) {
                continue;
            }

            $returnSegments = is_array($return) ? $return : [];

            $priceBase = $this->sumSegments($outbound, 'price_base') + $this->sumSegments($returnSegments, 'price_base');
            $priceTax = $this->sumSegments($outbound, 'price_tax') + $this->sumSegments($returnSegments, 'price_tax');

            $bookings[] = [
                'id_raw' => $row['id'],
                'id_pretty' => Helper::bookingIdToString($row['id']),
                'created' => $row['created'],
                'price_base' => $priceBase,
                'price_tax' => $priceTax,
                'price_total' => $priceBase + $priceTax,
                'outbound' => $this->bookingDirection($outbound),
                'return_flight' => $returnSegments === [] ? null : $this->bookingDirection($returnSegments),
            ];
        }

        echo new TwigRenderer()->renderPage('my/bookings/view.html.twig', [
            'bookings' => $bookings,
            'has_rows' => count($rows) > 0,
        ]);
    }

    /**
     * Flights the visitor kept for later.
     *
     * The list itself is a cookie written by the browser (see global.js): each
     * entry is an itinerary's ordered leg ids, which is everything needed to
     * rebuild it. Nothing about the flight is stored — prices and times are
     * read fresh here, so a saved card can never show a stale fare.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function saved(): void
    {
        $finder = new FlightFinder($this->connection());
        $presenter = new ItineraryPresenter();
        $flights = [];

        foreach ($this->savedKeys() as $key) {
            $ids = array_map(intval(...), explode('-', $key));
            $itinerary = $finder->itinerary($ids);

            // A saved flight can go stale — the search data is regenerated, or
            // the legs no longer chain. Drop those rather than draw a broken card.
            if ($itinerary === null) {
                continue;
            }

            $decoded = json_decode((string) json_encode($itinerary), false);

            if (! $decoded instanceof stdClass) {
                continue;
            }

            $built = $presenter->direction($decoded->itinerary);
            $direction = $built['direction'];

            $flights[] = [
                'key' => $key,
                'price' => $presenter->priceParts(
                    (float) $decoded->price_base + (float) $decoded->price_tax,
                ),
                'itinerary' => $direction,
                'search_url' => $this->searchUrl($decoded->itinerary),
            ];
        }

        echo new TwigRenderer()->renderPage('my/saved/view.html.twig', [
            'flights' => $flights,
            'has_rows' => $flights !== [],
        ]);
    }

    /**
     * The saved-flight keys from the cookie, in the order they were saved.
     *
     * The cookie is written by the browser, so treat it as untrusted input: only
     * keys that are hyphen-separated integers survive, and the list is capped so
     * a hand-edited cookie cannot turn one page render into thousands of queries.
     *
     * @return list<string>
     */
    private function savedKeys(): array
    {
        $raw = json_decode($_COOKIE[self::SAVED_COOKIE] ?? '', true);

        if (!is_array($raw)) {
            return [];
        }

        $keys = [];

        foreach ($raw as $key) {
            if (is_string($key) && preg_match('/^\d{1,19}(-\d{1,19})*$/', $key) === 1) {
                $keys[$key] = $key;
            }
        }

        return array_values(array_slice($keys, 0, self::SAVED_LIMIT));
    }

    /**
     * A link back to a fresh search for the same route and departure date, so a
     * saved flight is a starting point rather than a dead end.
     */
    private function searchUrl(object $itinerary): string
    {
        $segments = $itinerary->segments;
        $first = $segments[0];
        $last = $segments[array_key_last($segments)];

        return '/search?' . http_build_query([
            'from' => $first->depart->airport_code,
            'to' => $last->arrive->airport_code,
            'depart' => date('Y-m-d', strtotime($first->depart->date_time)),
            'triptype' => 'oneway',
            'class' => 'economy',
        ]);
    }

    /**
     * Collapse a stored itinerary (list of leg segments) into the view-model the
     * bookings templates render: the first leg's departure, the last leg's
     * arrival, the leading carrier, and a stops label.
     *
     * @param list<array<string, mixed>> $segments
     * @return array<string, mixed>
     */
    private function bookingDirection(array $segments): array
    {
        $first = $segments[0];
        $last = $segments[count($segments) - 1];

        return [
            'depart' => $first['depart'],
            'arrive' => $last['arrive'],
            'carrier' => $first['carrier'],
            'carrier_name' => $first['carrier_name'],
            'number' => $first['number'],
            'stops_label' => $this->stopsLabel(count($segments) - 1),
            'segments' => $segments,
        ];
    }

    private function stopsLabel(int $stops): string
    {
        return match (true) {
            $stops === 0 => 'Direct',
            $stops === 1 => '1 stop',
            default => $stops . ' stops',
        };
    }

    /**
     * @param list<array<string, mixed>> $segments
     */
    private function sumSegments(array $segments, string $key): float
    {
        return array_sum(array_map(static fn(array $segment): float => (float) $segment[$key], $segments));
    }
}
