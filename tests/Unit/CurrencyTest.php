<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Currency;

/**
 * The catalogue, and what it will accept as a currency code.
 *
 * Everything here is about a list somebody typed. The formatter trusts it
 * completely -- it reads `decimals` and pads to it, reads `group` and prints it
 * -- so a missing field is not a slightly wrong price, it is a broken page. The
 * rates are deliberately not here, and one test guards that too.
 */
final class CurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    public function testTheCatalogueIsWellFormed(): void
    {
        $currencies = Currency::all();

        self::assertNotEmpty($currencies);

        foreach ($currencies as $code => $currency) {
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $code, 'a code should be three capitals');
            self::assertSame($code, $currency->code);
            self::assertNotSame('', $currency->name, $code . ' has no name to show in the menu');
            self::assertNotSame('', $currency->symbol, $code . ' has no symbol');
            self::assertNotSame('', $currency->point, $code . ' has no decimal mark');
        }
    }

    /**
     * Decimals are the ISO minor unit, and only two values occur in this list.
     *
     * Asserted as a set rather than a range because a stray 1 or 3 would format
     * without complaint and be wrong everywhere at once.
     */
    public function testEveryCurrencyHasTwoDecimalsOrNone(): void
    {
        foreach (Currency::all() as $code => $currency) {
            self::assertContains($currency->decimals, [0, 2], $code . ' has an implausible minor unit');
        }
    }

    /**
     * The base currency is in the list like any other.
     *
     * The rates endpoint does not return the currency it was asked to use as a
     * base, so CAD would be the one currency missing from a menu built out of
     * the response. Listing it here means the switcher offers it and the
     * formatter needs no branch for it.
     */
    public function testTheBaseCurrencyIsListedLikeTheRest(): void
    {
        $base = Currency::base();

        self::assertSame('CAD', $base->code);
        self::assertArrayHasKey($base->code, Currency::all());
        self::assertSame(2, $base->decimals);
    }

    /**
     * And the catalogue holds no rates at all.
     *
     * A name and a symbol were written once; a rate is a measurement with a
     * date, and belongs in currency_rates. This asserts the split rather than
     * trusting it, because putting a rate back here would work perfectly and
     * quietly go stale.
     */
    public function testTheCatalogueCarriesNoRates(): void
    {
        /** @var array<string, array<string, mixed>> $list */
        $list = Config::get('currencies.list', []);

        foreach ($list as $code => $entry) {
            self::assertArrayNotHasKey('rate', $entry, $code . ' has a rate in config, which will rot there');
        }
    }

    /**
     * The default is listed first, because the switcher shows the list in order.
     */
    public function testTheDefaultLeadsTheList(): void
    {
        self::assertSame(Currency::base()->code, array_key_first(Currency::all()));
    }

    /**
     * Nothing but one of the catalogue's own strings is a currency.
     *
     * The cookie is whatever the browser sent. `usd` and ` USD` are somebody
     * editing it by hand, and the array is the case that catches a loose
     * comparison: `tb_currency[]=USD` in a URL makes `$_COOKIE` hold an array,
     * and `$value == 'USD'` would once have said yes to it. Same lesson as
     * ConsentTest.
     */
    #[DataProvider('notCurrencies')]
    public function testTryFromRejectsAnythingNotInTheList(mixed $code): void
    {
        self::assertNull(Currency::tryFrom($code));
    }

    /** @return iterable<string, array{mixed}> */
    public static function notCurrencies(): iterable
    {
        yield 'lower case' => ['usd'];
        yield 'padded' => [' USD'];
        yield 'empty' => [''];
        yield 'unlisted' => ['ZZZ'];
        yield 'a currency the ECB dropped' => ['RUB'];
        yield 'an array' => [['USD']];
        yield 'null' => [null];
        yield 'an integer' => [840];
    }

    public function testTryFromAcceptsAListedCode(): void
    {
        $usd = Currency::tryFrom('USD');

        self::assertNotNull($usd);
        self::assertSame('USD', $usd->code);
        self::assertSame(2, $usd->decimals);
    }
}
