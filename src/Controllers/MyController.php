<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Helper;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\View\TwigRenderer;

class MyController extends AbstractController
{
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
