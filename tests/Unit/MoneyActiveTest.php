<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Money;

/**
 * Which currency a visitor is shown, and what happens when that goes wrong.
 *
 * The cookie is whatever the browser sent, so most of this is about refusing
 * it. What makes these cases worth writing separately from CurrencyTest is the
 * consequence: a code that slips through does not raise anything, it reprices
 * the whole site for one visitor.
 *
 * Nothing here touches the rates table, and nothing here may: this file has to
 * behave the same whether a database is reachable or not. The first draft
 * asserted that a chosen currency with no rate falls back to Canadian dollars,
 * which passed with no database and failed with one -- and CI has one. That
 * assertion moved to MoneyActiveRatesTest, where the condition is made rather
 * than assumed.
 */
final class MoneyActiveTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
        unset($_COOKIE[Currency::COOKIE]);
        Money::forget();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[Currency::COOKIE]);
        Money::forget();
    }

    public function testNoCookieIsTheBaseCurrency(): void
    {
        self::assertSame('CAD', Currency::active()->code);
        self::assertSame('CAD', Money::active()->currency()->code);
    }

    public function testAListedCodeIsHonoured(): void
    {
        $_COOKIE[Currency::COOKIE] = 'JPY';

        self::assertSame('JPY', Currency::active()->code);
    }

    /**
     * Anything else is the base currency, quietly.
     *
     * The array is the case a loose comparison would have let through:
     * `tb_currency[]=USD` in a URL makes `$_COOKIE` hold an array, and
     * `$value == 'USD'` says yes to that. Same lesson as ConsentTest, and the
     * same one `$_COOKIE` teaches every time.
     */
    #[DataProvider('notCurrencies')]
    public function testAnythingElseFallsBackToTheBaseCurrency(mixed $cookie): void
    {
        $_COOKIE[Currency::COOKIE] = $cookie;

        self::assertSame('CAD', Currency::active()->code);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notCurrencies(): iterable
    {
        yield 'lower case' => ['jpy'];
        yield 'padded' => [' JPY'];
        yield 'empty' => [''];
        yield 'unlisted' => ['ZZZ'];
        yield 'dropped by the ECB' => ['RUB'];
        yield 'an array' => [['USD']];
        yield 'an integer' => [392];
        yield 'a whole sentence' => ['USD; DROP TABLE currency_rates'];
    }

    /**
     * And it is resolved once per request.
     *
     * A search page asks for a price around two hundred times; resolving the
     * rate each time was measured at 2ms of nothing.
     */
    public function testTheCurrencyIsResolvedOnce(): void
    {
        self::assertSame(Money::active(), Money::active());
    }

    /**
     * The memo does not outlive a change of cookie once it is dropped.
     *
     * Only tests change cookies mid-process, which is why forget() exists at
     * all -- but a memo that could not be cleared would make every test after
     * the first one meaningless.
     */
    public function testForgettingLetsTheCookieBeReread(): void
    {
        self::assertSame('CAD', Money::active()->currency()->code);

        $_COOKIE[Currency::COOKIE] = 'JPY';
        self::assertSame('CAD', Money::active()->currency()->code, 'the memo should still hold');

        Money::forget();
        self::assertSame('JPY', Currency::active()->code);
    }
}
