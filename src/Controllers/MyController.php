<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use stdClass;
use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Templater;

class MyController extends AbstractController
{
    /**
     * @return void
     * @throws \Exception
     */
    public function bookings(): void
    {
        $bookings = (new BookingRepository($this->connection()))->forSession(session_id());

        $templater = new Templater();

        if (count($bookings) > 0) {
            foreach ($bookings as $booking) {
                // Outbound flight
                $outbound = json_decode($booking['flight_outbound'] ?? '');
                $return = json_decode($booking['flight_return'] ?? '');

                // Skip rows whose stored flight JSON is corrupt
                if (! $outbound instanceof stdClass) {
                    continue;
                }

                // Calculating booking price
                $price_base = $outbound->price_base + ($return->price_base ?? 0);
                $price_tax = $outbound->price_tax + ($return->price_tax ?? 0);
                $price_total = $price_base + $price_tax;

                $templater
                    ->setPath('my/bookings')
                    ->setFilename('flight-outbound')
                    ->set()
                    ->setPlaceholder('booking_id_raw', $booking['id'])
                    ->setPlaceholder('booking_id_pretty', Helper::bookingIdToString($booking['id']))
                    ->setPlaceholder('booking_created', date('Y-m-d H:i', strtotime($booking['created'])))
                    ->setPlaceholder('airline_name', $outbound->carrier_name)
                    ->setPlaceholder('airline_logo_url', Cdn::getUrl(sprintf(
                        '%s/suppliers/%s.png',
                        Config::get('site.static.endpoint.images'),
                        $outbound->carrier,
                    )))
                    ->setPlaceholder('price_total', number_format($price_total, 2))
                    ->setPlaceholder('price_base', number_format($price_base, 2))
                    ->setPlaceholder('price_tax', number_format($price_tax, 2))
                    ->setPlaceholder('depart_time', date('Y-m-d H:i', strtotime($outbound->depart->date_time)))
                    ->setPlaceholder('depart_city', $outbound->depart->airport_city)
                    ->setPlaceholder('arrive_city', $outbound->arrive->airport_city)
                    ->setPlaceholder('flight_number', $outbound->number)
                    ->save();

                // Return flight - roundtrip
                if ($return instanceof stdClass) {
                    $templater
                        ->setPath('my/bookings')
                        ->setFilename('flight-return')
                        ->set()
                        ->setPlaceholder('airline_name', $return->carrier_name)
                        ->setPlaceholder('airline_logo_url', Cdn::getUrl(sprintf(
                            '%s/suppliers/%s.png',
                            Config::get('site.static.endpoint.images'),
                            $return->carrier,
                        )))
                        ->setPlaceholder('depart_time', date('Y-m-d H:i', strtotime($return->depart->date_time)))
                        ->setPlaceholder('depart_city', $return->depart->airport_city)
                        ->setPlaceholder('arrive_city', $return->arrive->airport_city)
                        ->setPlaceholder('flight_number', $return->number)
                        ->save();
                }
            }
        } else {
            $templater
                ->setPath('my/bookings')
                ->setFilename('empty')
                ->set()
                ->setPlaceholder('not_found_img', Cdn::getUrl(sprintf(
                    '%s/%s',
                    Config::get('site.static.endpoint.images'),
                    'not-found.png',
                )))
                ->save();
        }

        $bookings_list = $templater->render();

        echo $templater
            ->setPath('my/bookings')
            ->setFilename('view')
            ->set()
            ->setPlaceholder('bookings_list', $bookings_list)
            ->save()
            ->render();
    }

}
