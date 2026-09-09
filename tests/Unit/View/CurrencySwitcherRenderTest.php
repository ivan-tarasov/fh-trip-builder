<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Money;
use TripBuilder\Routes;
use TripBuilder\View\TwigRenderer;

/**
 * The switcher panel.
 *
 * Three things here fail quietly rather than loudly. A currency in the
 * catalogue with no row in the panel is a currency nobody can pick. A cookie
 * name that stops matching src/Currency.php gives a button that writes
 * something PHP never reads, so clicking it does nothing at all. And a panel
 * that renders without `hidden` puts a filter box and thirty buttons into the
 * tab order of every page on the site.
 */
final class CurrencySwitcherRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
        unset($_COOKIE[Currency::COOKIE]);
        Money::forget();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[Currency::COOKIE]);
        Money::forget();
    }

    public function testEveryCurrencyInTheCatalogueCanBePicked(): void
    {
        $html = $this->render();

        foreach (Currency::all() as $code => $currency) {
            self::assertStringContainsString('data-code="' . $code . '"', $html, $code . ' is not offered');
            self::assertStringContainsString('>' . $currency->name . '</span>', $html, $code . ' has no name shown');
        }

        self::assertSame(
            count(Currency::all()),
            substr_count($html, 'js-currency-choice'),
            'the panel should offer exactly the catalogue and nothing else',
        );
    }

    /**
     * The button writes the cookie PHP reads, and for as long as PHP expects.
     *
     * The lesson the Csrf constants taught, in the one other place two
     * languages have to spell the same thing: a mismatch here is a switcher
     * that looks like it works and silently does nothing.
     */
    public function testThePanelCarriesTheCookieTheBrowserWillWrite(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-cookie="' . Currency::COOKIE . '"', $html);
        self::assertStringContainsString('data-max-age="' . Currency::MAX_AGE . '"', $html);
    }

    /**
     * Shut, and out of the tab order, until somebody opens it.
     */
    public function testThePanelStartsHiddenAndTheTriggerSaysSo(): void
    {
        $html = $this->render();

        self::assertStringContainsString('aria-expanded="false"', $html);
        self::assertStringContainsString('aria-controls="currency-panel"', $html);
        self::assertMatchesRegularExpression('/id="currency-panel"[^>]*\shidden/s', $html);
    }

    /**
     * The trigger and the tick agree with the currency actually in use.
     *
     * Asserted against `Money::active()` rather than against a code this test
     * chose, because the two are not always the same thing: a cookie naming a
     * currency with no rate on the table resolves to the base currency, and
     * that is the right answer -- what the trigger must never do is name one
     * currency while the prices below it are in another.
     *
     * That is also why this reads the effective currency instead of setting a
     * cookie and expecting it: the first draft set JPY and asserted JPY, which
     * passed with a database and failed without one.
     */
    public function testTheTriggerAndTheTickAgreeWithTheCurrencyInUse(): void
    {
        $active = Money::active()->currency()->code;
        $html = $this->render();

        self::assertStringContainsString('</i>' . $active, $html, 'the trigger should name the active currency');
        self::assertMatchesRegularExpression(
            '/data-code="' . $active . '"[^>]*aria-current="true"/s',
            $html,
            'the active currency should be the marked one',
        );
        self::assertSame(1, substr_count($html, 'aria-current="true"'), 'exactly one row is current');
    }

    /**
     * Searching matches the name as well as the code, without its accents.
     *
     * Two names in the catalogue carry one -- Icelandic Króna and Polish Złoty
     * -- and without folding them "kron" finds three krona and misses the
     * fourth, which reads as a broken list rather than a literal search.
     */
    public function testTheSearchTextIsFoldedToPlainLetters(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-search="isk icelandic krona"', $html);
        self::assertStringContainsString('data-search="pln polish zloty"', $html);
    }

    /**
     * The panel says where the rates came from and when.
     *
     * The line that earns the right to show a converted price at all. With no
     * rates fetched it says so instead of naming a date, because a confident
     * wrong date is the one thing this feature must not do.
     */
    public function testThePanelNamesItsSource(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression(
            '/class="currency__source">\s*(European Central Bank rates,|Built-in reference rates)/s',
            $html,
        );
    }

    /**
     * And currency is no longer a menu entry.
     *
     * It left `site.main-menu` because that map is keyed by URL and both its
     * readers use the key as an href. Asserted so it does not drift back: an
     * entry there would render as a link to a page that does not exist, which
     * is what the footer's own navigation test was written about.
     */
    public function testCurrencyIsNotAMenuEntry(): void
    {
        foreach (array_keys(Config::get('site.main-menu', [])) as $url) {
            self::assertStringNotContainsString(
                'currency',
                (string) $url,
                'currency is a control, not a destination -- see partials/currency-switcher.html.twig',
            );
        }
    }

    /**
     * The `soon` flag went with it.
     *
     * Currency was its last user, and an unused branch in the header is how the
     * app grows a second currency switcher next year.
     */
    public function testTheRetiredPlaceholderFlagIsGone(): void
    {
        foreach (Config::get('site.main-menu', []) as $url => $item) {
            self::assertArrayNotHasKey('soon', $item, $url . ' still carries a retired flag');
        }
    }

    private function render(): string
    {
        Routes::setCurrentPage('/');

        return new TwigRenderer()->render('partials/currency-switcher.html.twig');
    }
}
