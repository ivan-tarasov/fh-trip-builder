<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Money;
use TripBuilder\View\BookingPresenter;

final class BookingPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        // carrierLogo() and the layover notices read config.
        $_ENV['AWS_CLOUDFRONT'] = 'cdn.example.test';
        new Config('common');
        unset($_COOKIE[Currency::COOKIE]);
        Money::forget();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[Currency::COOKIE]);
        Money::forget();
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

    /**
     * A booking reads in the currency it was made in, whatever the visitor has
     * chosen since.
     *
     * The most important assertion in the currency work, and the reason the
     * booking carries its own two columns. BookingPresenter shares one
     * ItineraryPresenter, so a purely ambient lookup would have re-rendered
     * every past booking at today's cookie and today's rate -- restating what
     * somebody agreed to pay, on the page headed "Total paid".
     *
     * The cookie is set to something else on purpose. If it ever leaks through,
     * this fails.
     */
    public function testABookingReadsInItsOwnCurrencyAndNotTheVisitorsCookie(): void
    {
        $_COOKIE[Currency::COOKIE] = 'EUR';
        Money::forget();

        $booking = self::presenter()->booking(self::row([
            'currency' => 'JPY',
            'currency_rate' => 111.32,
        ]));

        self::assertSame('JPY', $booking['price_total']['code']);
        self::assertSame('¥', $booking['price_total']['symbol']);
        self::assertNull($booking['price_total']['cents'], 'yen has no minor unit');
        // 1000 CAD at the frozen rate, not at whatever EUR is worth today.
        self::assertSame('111,320', $booking['price_total']['whole']);
    }

    /**
     * And it reads at the rate it was made at, not today's.
     *
     * Two bookings for the same dollar amount at different rates have to report
     * different figures; a presenter that looked the rate up would collapse
     * them onto one.
     */
    public function testTwoBookingsAtDifferentRatesReportDifferentTotals(): void
    {
        $march = self::presenter()->booking(self::row(['currency' => 'JPY', 'currency_rate' => 95.0]));
        $today = self::presenter()->booking(self::row(['currency' => 'JPY', 'currency_rate' => 111.32]));

        self::assertSame('95,000', $march['price_total']['whole']);
        self::assertSame('111,320', $today['price_total']['whole']);
    }

    /**
     * A row written before the columns existed reads in Canadian dollars.
     *
     * Not in the cookie's currency, which would be the tempting default and
     * would silently convert a figure that was never converted. Those rows
     * genuinely were dollars, which is why the column defaults say so.
     */
    public function testALegacyRowWithoutTheColumnsReadsAsCanadianDollars(): void
    {
        $_COOKIE[Currency::COOKIE] = 'JPY';
        Money::forget();

        $row = self::row();
        unset($row['currency'], $row['currency_rate']);

        $booking = self::presenter()->booking($row);

        self::assertSame('CAD', $booking['price_total']['code']);
        self::assertSame('1,000', $booking['price_total']['whole']);
    }

    /**
     * A currency dropped from the catalogue after somebody booked in it.
     *
     * The figures on the row are dollars either way, so falling back reads as
     * an unconverted price rather than a wrong one -- and it does not throw,
     * which is what matters on somebody's own bookings page.
     */
    public function testACurrencyNoLongerOfferedFallsBackRatherThanFailing(): void
    {
        $booking = self::presenter()->booking(self::row([
            'currency' => 'RUB',
            'currency_rate' => 62.45,
        ]));

        self::assertSame('CAD', $booking['price_total']['code']);
        self::assertSame('1,000', $booking['price_total']['whole']);
    }

    /**
     * Base, tax and total in a booking's own currency still add up.
     */
    public function testTheReceiptAddsUpInTheBookingsCurrency(): void
    {
        $booking = self::presenter()->booking(self::row([
            'currency' => 'JPY',
            'currency_rate' => 111.32,
        ]));

        $whole = static fn(array $part): int => (int) str_replace(',', '', $part['whole']);

        self::assertSame(
            $whole($booking['price_total']),
            $whole($booking['price_base']) + $whole($booking['price_tax']),
        );
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

    public function testTheSummaryNamesTheLeadAndCountsTheRest(): void
    {
        // The bookings list reads its rows without joining, so a count is all it
        // has. A party of three still must not read as a trip for one.
        $booking = self::presenter()->booking(self::row(), travellerCount: 3);

        self::assertSame('Ada Lovelace + 2', $booking['passenger_summary']);
    }

    public function testOneTravellerIsJustTheirName(): void
    {
        self::assertSame(
            'Ada Lovelace',
            self::presenter()->booking(self::row(), travellerCount: 1)['passenger_summary'],
        );

        // Written before travellers were rows of their own, so there is nothing
        // to count and nothing to add.
        self::assertSame(
            'Ada Lovelace',
            self::presenter()->booking(self::row())['passenger_summary'],
        );
    }

    public function testTheDetailPageCountsTheTravellersItAlreadyHolds(): void
    {
        // Handed the travellers themselves, it counts those rather than relying
        // on a number the caller also has to remember to pass.
        $booking = self::presenter()->booking(self::row(), [
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'type' => 'A', 'dob' => '1990-01-01'],
            ['first_name' => 'Mary', 'last_name' => 'Somerville', 'type' => 'A', 'dob' => '1992-02-02'],
        ]);

        self::assertSame('Ada Lovelace + 1', $booking['passenger_summary']);
    }
}
