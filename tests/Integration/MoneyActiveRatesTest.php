<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

use DateTimeImmutable;
use TripBuilder\Currency;
use TripBuilder\Money;
use TripBuilder\View\BookingPresenter;

/**
 * The chosen currency, against the rates actually on the table.
 *
 * The cookie handling is MoneyActiveTest's job and needs no database. This is
 * the other half: whether the rate that reaches the formatter is the one stored,
 * and what happens when there is not a usable one.
 *
 * That second case has to be built rather than waited for. Every currency the
 * catalogue offers has a seeded row, so "no rate" only happens on a machine
 * somebody has cleared -- and a fallback nothing exercises is a fallback nobody
 * knows is broken.
 */
final class MoneyActiveRatesTest extends IntegrationTestCase
{
    /** Far enough ahead that it always wins the latest-per-code read. */
    private const string FUTURE = '2099-01-01';

    protected function setUp(): void
    {
        new \TripBuilder\Config('common');
        unset($_COOKIE[Currency::COOKIE]);
        Money::forget();
    }

    protected function tearDown(): void
    {
        // Nothing was written, so there is nothing to tidy -- and a skip
        // raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM currency_rates WHERE rate_date = ?', [self::FUTURE]);
        unset($_COOKIE[Currency::COOKIE]);
        Money::forget();
    }

    /**
     * A chosen currency converts at the rate on the table.
     *
     * Asserted against the stored figure rather than a literal, so this cannot
     * start failing the next time somebody runs `currency:rates`.
     */
    public function testAChosenCurrencyUsesTheStoredRate(): void
    {
        $stored = (float) $this->connection()->fetchValue(
            'SELECT rate FROM currency_rates WHERE code = ? ORDER BY rate_date DESC LIMIT 1',
            ['JPY'],
        );

        self::assertGreaterThan(0, $stored, 'JPY should have a seeded rate to test against');

        $_COOKIE[Currency::COOKIE] = 'JPY';
        $money = Money::active();

        self::assertSame('JPY', $money->currency()->code);
        self::assertSame(
            (string) (int) round(100.0 * $stored),
            str_replace(',', '', $money->parts(100.0)['whole']),
            'the rate used should be the rate stored',
        );
    }

    /**
     * A booking ignores the cookie even when the cookie would work.
     *
     * BookingPresenterTest asserts this too, and passes without a database --
     * but only because `Money::active()` cannot resolve a rate there, so the
     * ambient currency and the fallback are the same thing and the assertion
     * cannot tell them apart. Mutating `Money::base()` to `Money::active()` in
     * the presenter's fallback went green locally and red in CI.
     *
     * So the precondition below is the point of this test: it proves the cookie
     * *does* resolve, which means Canadian dollars can only have come from the
     * booking's own columns.
     */
    public function testABookingIgnoresTheCookieEvenWhenTheCookieResolves(): void
    {
        // Money reads the rates itself, so this asks for a connection only to
        // turn a missing database into a skip rather than a failed
        // precondition. See IntegrationTestCase::connectionOrNull().
        $this->connection();

        $_COOKIE[Currency::COOKIE] = 'JPY';
        Money::forget();

        self::assertSame(
            'JPY',
            Money::active()->currency()->code,
            'precondition: JPY must be resolvable, or this test proves nothing',
        );

        // A row from before the currency columns existed.
        $booking = new BookingPresenter(now: new DateTimeImmutable('2026-09-04 12:00:00'))
            ->booking(self::legacyRow());

        self::assertSame('CAD', $booking['price_total']['code']);
        self::assertSame('1,000', $booking['price_total']['whole']);
    }

    /**
     * The smallest booking row the presenter will read, with no currency on it.
     *
     * @return array<string, mixed>
     */
    private static function legacyRow(): array
    {
        $segment = [
            'id' => 1, 'carrier' => 'AC', 'carrier_name' => 'Air Canada', 'number' => 'AC-100',
            'duration' => 120, 'cabin_code' => 'Y', 'price_base' => 500.0, 'price_tax' => 50.0,
            'depart' => [
                'airport_code' => 'YUL', 'airport_name' => 'YUL', 'airport_city' => 'Montreal',
                'airport_country' => 'Canada', 'date_time' => '2026-09-08 07:00',
            ],
            'arrive' => [
                'airport_code' => 'LHR', 'airport_name' => 'LHR', 'airport_city' => 'London',
                'airport_country' => 'United Kingdom', 'date_time' => '2026-09-08 19:00',
            ],
        ];

        return [
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
            'flight_outbound' => json_encode([$segment]),
            'flight_return' => null,
        ];
    }

    /**
     * A rate of zero is not a rate, and must not be used.
     *
     * This is the bug this test exists for. Multiplying by zero would render
     * every price on the site as nothing, in a page that returns 200 and looks
     * entirely normal -- so "no usable rate" has to be a different value from
     * "a rate of zero", and the fallback has to be the base currency.
     */
    public function testAZeroRateFallsBackToTheBaseCurrencyRatherThanPricingEverythingAtNothing(): void
    {
        $this->connection()->execute(
            'INSERT INTO currency_rates (code, rate_date, rate, fetched_at) VALUES (?, ?, 0, NOW())',
            ['JPY', self::FUTURE],
        );

        $_COOKIE[Currency::COOKIE] = 'JPY';
        $money = Money::active();

        self::assertSame('CAD', $money->currency()->code);
        self::assertSame('$100.00', $money->parts(100.0)['text'], 'prices must stay real');
    }
}
