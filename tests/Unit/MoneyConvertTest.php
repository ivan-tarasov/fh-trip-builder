<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Money;

/**
 * The bare converted number, which the fare calendar is the only caller of.
 *
 * Everything else on the site asks for a formatted price, because a template
 * cannot be trusted to write one. The calendar can: it is handed a base and a
 * tax per day and scales them for the party in the browser, so it needs figures
 * rather than strings and does its own formatting from the currency the same
 * response declared.
 *
 * That split is the whole reason this method exists, and the reason it is
 * tested apart: `convert()` returning a rounded or formatted value would break
 * the party arithmetic silently, because the browser would be scaling something
 * that had already been rounded.
 */
final class MoneyConvertTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    public function testConvertMultipliesAndDoesNotRound(): void
    {
        $money = new Money($this->currency('JPY'), 111.32);

        // 136.51 is a real day price out of route_day_price. Rounded to whole
        // yen it would be 15196, and the browser would then scale *that* for a
        // party -- multiplying an error by three.
        self::assertSame(136.51 * 111.32, $money->convert(136.51));
        self::assertNotSame(round(136.51 * 111.32), $money->convert(136.51));
    }

    /**
     * The base currency passes through untouched.
     *
     * Not "approximately untouched": a CAD visitor's calendar must show the
     * same figures it showed before any of this existed, and a rate of 1.0
     * multiplied in has to be exact rather than nearly so.
     */
    public function testTheBaseCurrencyIsUnchangedByConversion(): void
    {
        foreach ([0.0, 1.0, 136.51, 9999.99] as $amount) {
            self::assertSame($amount, Money::base()->convert($amount));
        }
    }

    /**
     * Scaling a converted amount and converting a scaled one agree.
     *
     * Which is what makes it safe for the server to convert and the browser to
     * apply the party afterwards. If these two ever disagreed, a calendar cell
     * and the total under it would drift apart for anyone but a lone adult.
     */
    public function testConvertingCommutesWithScalingForAParty(): void
    {
        $money = new Money($this->currency('JPY'), 111.32);
        // A mixed party: two adults, a child at three quarters, an infant at a
        // tenth -- the shares Party reports.
        $share = 2 + 0.75 + 0.10;

        self::assertSame(
            $money->convert(136.51) * $share,
            $money->convert(136.51 * $share),
        );
    }

    private function currency(string $code): Currency
    {
        $currency = Currency::tryFrom($code);

        self::assertNotNull($currency);

        return $currency;
    }
}
