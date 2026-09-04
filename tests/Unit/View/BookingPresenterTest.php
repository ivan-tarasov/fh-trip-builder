<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\View\BookingPresenter;

final class BookingPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        // carrierLogo() and the layover notices read config.
        $_ENV['AWS_CLOUDFRONT'] = 'cdn.example.test';
        new Config('common');
    }

    /**
     * @return array<string, mixed>
     */
    private static function segment(string $from, string $to, string $depart, string $arrive): array
    {
        return [
            'id' => 1,
            'carrier' => 'AC',
            'carrier_name' => 'Air Canada',
            'number' => 'AC-100',
            'duration' => 120,
            'cabin_code' => 'C',
            'price_base' => 500.0,
            'price_tax' => 50.0,
            'depart' => [
                'airport_code' => $from, 'airport_name' => $from, 'airport_city' => $from,
                'airport_country' => 'Canada', 'date_time' => $depart,
            ],
            'arrive' => [
                'airport_code' => $to, 'airport_name' => $to, 'airport_city' => $to,
                'airport_country' => 'United Kingdom', 'date_time' => $arrive,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 100001,
            'reference' => 'K7PQ2M',
            'status' => 'confirmed',
            'created' => '2026-09-01 10:00:00',
            'departure_time' => '2026-09-08 07:00:00',
            'passenger_first' => 'Ada',
            'passenger_last' => 'Lovelace',
            'contact_email' => 'ada@example.test',
            'contact_phone' => '+15145550100',
            'fare_brand' => 'Flex',
            'card_brand' => 'Visa',
            'card_last4' => '4242',
            'price_base' => 900.00,
            'price_tax' => 100.00,
            'flight_outbound' => json_encode([self::segment('YUL', 'LHR', '2026-09-08 07:00', '2026-09-08 19:00')]),
            'flight_return' => json_encode([self::segment('LHR', 'YUL', '2026-09-15 09:00', '2026-09-15 11:30')]),
        ];
    }

    private static function presenter(string $now = '2026-09-04 12:00:00'): BookingPresenter
    {
        return new BookingPresenter(now: new DateTimeImmutable($now));
    }

    public function testPriceComesFromTheColumnsNotTheStoredSegments(): void
    {
        // The segments deliberately total 550; the columns say 1000. The
        // columns are what a card was charged.
        $booking = self::presenter()->booking(self::row());

        self::assertSame('1,000', $booking['price_total']['whole']);
        self::assertSame('00', $booking['price_total']['cents']);
    }

    public function testALegacyRowReportsNoPriceAndNoReference(): void
    {
        $booking = self::presenter()->booking(self::row([
            'reference' => '',
            'price_base' => 0.00,
            'price_tax' => 0.00,
        ]));

        // Six rows in the live table look like this. Showing the segment sum
        // would present a search price nobody was ever charged.
        self::assertNull($booking['price_total']);
        self::assertNull($booking['reference']);
    }

    public function testARoundTripIsStillUpcomingOnceOnlyTheOutboundHasFlown(): void
    {
        // Outbound 8 Sep, return lands 15 Sep. On the 10th the traveller still
        // has a flight to catch, so this must not be filed under Past.
        $booking = self::presenter('2026-09-10 12:00:00')->booking(self::row());

        self::assertFalse($booking['is_past']);
        self::assertNull($booking['departs_in']);
    }

    public function testTheTripIsPastOnlyAfterTheLastArrival(): void
    {
        $before = self::presenter('2026-09-15 11:00:00')->booking(self::row());
        $after = self::presenter('2026-09-15 12:00:00')->booking(self::row());

        self::assertFalse($before['is_past']);
        self::assertTrue($after['is_past']);
    }

    public function testANullDepartureTimeFallsBackToTheFirstSegment(): void
    {
        $booking = self::presenter()->booking(self::row(['departure_time' => null]));

        self::assertSame('2026-09-08 07:00', $booking['starts_at']->format('Y-m-d H:i'));
    }

    public function testRebookCarriesTheCabinAndTripTypeThatWereBought(): void
    {
        $booking = self::presenter()->booking(self::row());

        // Offering a business round trip back as an economy one-way is a worse
        // answer than not offering it.
        self::assertSame(
            ['from' => 'YUL', 'to' => 'LHR', 'triptype' => 'roundtrip', 'class' => 'business'],
            $booking['rebook'],
        );
    }

    public function testAOneWayBookingRebooksAsOneWay(): void
    {
        $booking = self::presenter()->booking(self::row(['flight_return' => null]));

        self::assertNull($booking['return']);
        self::assertSame('oneway', $booking['rebook']['triptype']);
    }

    public function testCancelledIsReportedWithoutHidingTheBooking(): void
    {
        $booking = self::presenter()->booking(self::row(['status' => 'cancelled']));

        self::assertTrue($booking['is_cancelled']);
        self::assertSame('Cancelled', $booking['status_label']);
        self::assertFalse($booking['is_past']);
    }

    public function testACorruptRowIsSkippedRatherThanDrawnEmpty(): void
    {
        self::assertNull(self::presenter()->booking(self::row(['flight_outbound' => 'not json'])));
    }

    public function testTheDirectionsAreTheShapeTheSearchCardsRender(): void
    {
        $booking = self::presenter()->booking(self::row());

        // The whole reason a booking can reuse search/cards/itinerary.html.twig.
        foreach (['depart_time', 'depart_city', 'arrive_time', 'duration', 'stops_label', 'route', 'segments'] as $key) {
            self::assertArrayHasKey($key, $booking['outbound']);
        }

        self::assertSame('Business', $booking['outbound']['segments'][0]['cabin']);
    }
}
