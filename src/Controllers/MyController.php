<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use stdClass;
use TripBuilder\Helper;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\View\TwigRenderer;

class MyController extends AbstractController
{
    /**
     * @return void
     * @throws \Exception
     */
    public function bookings(): void
    {
        $rows = (new BookingRepository($this->connection()))->forSession(session_id());

        $bookings = [];

        foreach ($rows as $row) {
            $outbound = json_decode($row['flight_outbound'] ?? '');
            $return   = json_decode($row['flight_return'] ?? '');

            // Skip rows whose stored flight JSON is corrupt.
            if (! $outbound instanceof stdClass) {
                continue;
            }

            $priceBase = $outbound->price_base + ($return->price_base ?? 0);
            $priceTax  = $outbound->price_tax + ($return->price_tax ?? 0);

            $bookings[] = [
                'id_raw'        => $row['id'],
                'id_pretty'     => Helper::bookingIdToString($row['id']),
                'created'       => $row['created'],
                'price_base'    => $priceBase,
                'price_tax'     => $priceTax,
                'price_total'   => $priceBase + $priceTax,
                'outbound'      => $outbound,
                'return_flight' => $return instanceof stdClass ? $return : null,
            ];
        }

        echo (new TwigRenderer())->renderPage('my/bookings/view.html.twig', [
            'bookings' => $bookings,
            'has_rows' => count($rows) > 0,
        ]);
    }

}
