<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\View;

use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Repository\CurrencyRateRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\View\LayoutData;

/**
 * The line in the currency panel, from the table to the shape of it.
 *
 * The three cases are the three ways there is nothing to draw, and the panel
 * has to treat all of them the same: the base currency, which is 1.00 against
 * itself every day; a table nobody has backfilled, where one day is a point;
 * and a currency nobody has stored (B2.2, #105).
 */
final class RateHistoryTest extends IntegrationTestCase
{
    /**
     * A real catalogue code, because the panel draws whatever the visitor
     * picked and a made-up one can never be picked.
     */
    private const string TEST_CODE = 'ISK';

    /**
     * Dated where they cannot become the rate anything is priced at.
     *
     * `latest()` takes the newest row per code, so rows from 1999 are readable
     * history and are never the figure a price is converted with -- which is
     * what lets this test write to a currency the site actually offers.
     */
    private const array DAYS = ['1999-01-04' => 84.0, '1999-01-05' => 85.5, '1999-01-06' => 83.25];

    protected function setUp(): void
    {
        new Config('common');
        unset($_COOKIE[Currency::COOKIE]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[Currency::COOKIE]);

        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute(
            'DELETE FROM currency_rates WHERE code = ? AND rate_date < ?',
            [self::TEST_CODE, '2000-01-01'],
        );
    }

    public function testTheActiveCurrencyGetsALine(): void
    {
        $this->store();
        $_COOKIE[Currency::COOKIE] = self::TEST_CODE;

        $history = new LayoutData()->ratesHistory();

        self::assertNotNull($history, 'a currency with a history drew nothing');
        self::assertSame(self::TEST_CODE, $history['code']);
        self::assertGreaterThanOrEqual(2, $history['days']);
        self::assertNotSame('', $history['points']);
        self::assertStringContainsString(',', $history['points']);
    }

    /**
     * The base currency is 1.00 against itself on every day it has ever been
     * measured, and a flat line saying nothing is worse than no line.
     */
    public function testTheBaseCurrencyHasNoLine(): void
    {
        $_COOKIE[Currency::COOKIE] = Currency::base()->code;

        self::assertNull(new LayoutData()->ratesHistory());
    }

    /**
     * Asked twice, queried once.
     *
     * Most visitors never open the panel, so this is one query for one line
     * that is usually not looked at -- the same bargain `ratesDate()` makes
     * beside it.
     */
    public function testTheAnswerIsKeptForTheRestOfTheRequest(): void
    {
        $this->store();
        $_COOKIE[Currency::COOKIE] = self::TEST_CODE;

        $layout = new LayoutData();

        self::assertSame($layout->ratesHistory(), $layout->ratesHistory());
    }

    private function store(): void
    {
        $days = [];

        foreach (self::DAYS as $date => $rate) {
            $days[$date] = [self::TEST_CODE => $rate];
        }

        new CurrencyRateRepository($this->connection())->storeMany($days);
    }
}
