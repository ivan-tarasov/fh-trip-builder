<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

use TripBuilder\Currency;
use TripBuilder\Money;

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
