<?php

namespace TripBuilder\Controllers;

use TripBuilder\AmazonS3;
use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Templater;

class MyController extends AbstractController
{
    /**
     * @return void
     */
    public function index(): void
    {
        echo 'My::index()';
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function bookings(): void
    {
        $this->db->where('session_id', session_id());
        $bookings = $this->db->get('bookings');

        if ($this->db->count == 0) {
            echo 'No bookings';
        }

        $templater = new Templater();

        foreach ($bookings as $booking) {
            // Outbound flight
            $outbound = json_decode($booking['flight_outbound']);
            $return   = json_decode($booking['flight_return'] ?? '');

            // Calculating booking price
            $price_base  = $outbound->price_base + ($return->price_base ?? 0);
            $price_tax   = $outbound->price_tax + ($return->price_tax ?? 0);
            $price_total = $price_base + $price_tax;

            $templater
                ->setPath('my/bookings')
                ->setFilename('flight-outbound')
                ->set()
                ->setPlaceholder('booking_id', Helper::bookingIdToString($booking['id']))
                ->setPlaceholder('booking_created', date('Y-m-d H:i', strtotime($booking['created'])))
                ->setPlaceholder('airline_logo_url', AmazonS3::getUrl(sprintf(
                    '%s/suppliers/%s.png',
                    Config::get('site.static.endpoint.images'),
                    $outbound->carrier
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
            if (!empty($return)) {
                $templater
                    ->setPath('my/bookings')
                    ->setFilename('flight-return')
                    ->set()
                    ->setPlaceholder('airline_logo_url', AmazonS3::getUrl(sprintf(
                        '%s/suppliers/%s.png',
                        Config::get('site.static.endpoint.images'),
                        $return->carrier
                    )))
                    ->setPlaceholder('depart_time', date('Y-m-d H:i', strtotime($return->depart->date_time)))
                    ->setPlaceholder('depart_city', $return->depart->airport_city)
                    ->setPlaceholder('arrive_city', $return->arrive->airport_city)
                    ->setPlaceholder('flight_number', $return->number)
                    ->save();
            }
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
