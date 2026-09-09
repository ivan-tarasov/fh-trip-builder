<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Consent;
use TripBuilder\Routes;
use TripBuilder\View\TwigRenderer;

/**
 * What the page actually loads, against what the visitor agreed to.
 *
 * ConsentTest covers the decision; this covers whether the templates obey it.
 * They are separate failures: `Consent::granted()` can be perfectly right while
 * an `{% if %}` in the header is missing, and the result -- a session recorder
 * running on somebody who declined -- looks exactly like a working page.
 *
 * Both vendors are asserted by the host they load from rather than by the shape
 * of the snippet, because the snippet is vendor boilerplate that may be
 * replaced wholesale and the host is the thing that means "a request left the
 * browser".
 */
final class CookieNoticeRenderTest extends TestCase
{
    private const string METRIKA = 'mc.yandex.ru';
    private const string ANALYTICS = 'googletagmanager.com';

    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
        unset($_COOKIE[Consent::COOKIE]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[Consent::COOKIE]);
    }

    public function testNoAnswerLoadsNeitherVendorAndAsks(): void
    {
        $html = $this->page();

        self::assertStringNotContainsString(self::METRIKA, $html);
        self::assertStringNotContainsString(self::ANALYTICS, $html);
        self::assertStringContainsString('js-cookie-notice', $html, 'an unanswered visitor should be asked');
    }

    public function testConsentLoadsBothVendorsAndStopsAsking(): void
    {
        $_COOKIE[Consent::COOKIE] = Consent::GRANTED;
        $html = $this->page();

        self::assertStringContainsString(self::METRIKA, $html);
        self::assertStringContainsString(self::ANALYTICS, $html);
        self::assertStringNotContainsString('js-cookie-notice', $html);
    }

    public function testDecliningLoadsNeitherAndStopsAsking(): void
    {
        $_COOKIE[Consent::COOKIE] = Consent::DENIED;
        $html = $this->page();

        self::assertStringNotContainsString(self::METRIKA, $html);
        self::assertStringNotContainsString(self::ANALYTICS, $html);
        self::assertStringNotContainsString('js-cookie-notice', $html, 'a declined visitor should not be asked again');
    }

    /**
     * A value this code does not know is not permission.
     *
     * The render half of ConsentTest's fail-closed case: whatever a browser
     * sends, nothing loads unless it is the one word.
     */
    public function testAnUnknownCookieValueLoadsNothing(): void
    {
        $_COOKIE[Consent::COOKIE] = 'yes';
        $html = $this->page();

        self::assertStringNotContainsString(self::METRIKA, $html);
        self::assertStringNotContainsString(self::ANALYTICS, $html);
        self::assertStringContainsString('js-cookie-notice', $html);
    }

    /**
     * The notice hands the browser the three strings it needs to answer.
     *
     * global.js writes the cookie, so it has to know the name, the lifetime and
     * both values -- and the way it learns them is these attributes. If they
     * stop matching Consent's constants, the button writes a cookie that PHP
     * never reads and clicking it does nothing at all, silently. This is the
     * lesson the Csrf constants taught, in the one other place two languages
     * have to spell the same thing.
     */
    public function testTheNoticeCarriesTheNamesTheBrowserWillWrite(): void
    {
        $notice = self::notice($this->page());

        self::assertStringContainsString('data-cookie="' . Consent::COOKIE . '"', $notice);
        self::assertStringContainsString('data-max-age="' . Consent::MAX_AGE . '"', $notice);
        self::assertStringContainsString('data-consent="' . Consent::GRANTED . '"', $notice);
        self::assertStringContainsString('data-consent="' . Consent::DENIED . '"', $notice);
    }

    /**
     * Declining is a button in the same row, not small print.
     *
     * Both choices carry the same class, so neither can be quietly demoted to a
     * link while the other stays a button.
     */
    public function testBothAnswersAreOfferedTheSameWay(): void
    {
        self::assertSame(
            2,
            substr_count(self::notice($this->page()), 'class="cookie-notice__button'),
            'the notice should offer exactly two buttons',
        );
    }

    /**
     * Just the notice, and this is not fussiness.
     *
     * The page these tests render is the cookies page, which carries an "Ask me
     * again" button with the same `data-cookie` attribute on it. Asserted
     * against the whole document, the attribute test passed with the notice's
     * own attribute renamed -- it was reading the button underneath. Found by
     * mutating the template and watching the test stay green.
     */
    private static function notice(string $html): string
    {
        $start = strpos($html, '<section class="cookie-notice');

        if ($start === false) {
            return '';
        }

        $end = (int) strpos($html, '</section>', $start);

        return substr($html, $start, $end - $start);
    }

    /**
     * A whole page, header and footer included, which is what carries both the
     * counters and the notice.
     */
    private function page(): string
    {
        Routes::setCurrentPage('/');

        return new TwigRenderer()->render('legal/view.html.twig', [
            'document' => ['title' => 'Cookies', 'icon' => 'fa-cookie-bite', 'summary' => 'x', 'slug' => 'cookies'],
            'more' => [],
            'breadcrumbs' => [],
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);
    }
}
