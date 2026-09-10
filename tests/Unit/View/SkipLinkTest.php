<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Routes;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\TwigRenderer;

/**
 * The link that gets a keyboard past the header.
 *
 * Every page in this app opens with the same header -- the brand, the section
 * tray, the menu and the currency switcher -- so without this a keyboard
 * reader tabs through all of it on every page before reaching what the page is
 * about.
 *
 * Three things here break silently, and each has a test below.
 *
 * The link points at `#main`, which is the box `layout.html.twig` wraps every
 * page's content in. It is not a <main> element, and that is the decision this
 * suite exists to hold: thirteen templates open a <main> of their own and
 * three -- the homepage, the search results and the 404 -- open none, so there
 * is no <main> to point at that every page has. The layout's wrapper is drawn
 * in one place and cannot drift. Move the id onto a <main> and the link is
 * dead on three pages, with nothing to show for it: a dangling fragment does
 * not throw, it just does nothing when it is followed.
 *
 * The target has to be able to hold focus. Without `tabindex="-1"` a browser
 * moves only the sequential focus starting point -- the next Tab lands in the
 * right place, but focus itself never left the header, so a screen reader is
 * still reading the menu out.
 *
 * And it has to be first. A focusable element added above it in the header
 * makes it the second stop, which is one stop later than the only position
 * that does anything.
 */
final class SkipLinkTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
    }

    /**
     * Two pages, chosen for what they disagree about.
     *
     * /about opens a <main>, by way of the article partial. The 404 opens none
     * at all, and is one of the three pages that made the wrapper the target
     * rather than the landmark.
     *
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'a page with a <main>' => ['/about'];
        yield 'a page without one' => ['/nothing-here'];
    }

    #[DataProvider('pages')]
    public function testTheSkipLinkIsTheFirstFocusableThingInTheDocument(string $path): void
    {
        $html = $this->page($path);

        preg_match_all('/<(a|button|input|select|textarea)\b[^>]*>/i', $html, $stops);

        self::assertNotEmpty($stops[0], 'sanity: the page has focusable elements');
        self::assertStringContainsString(
            'class="skip-link"',
            $stops[0][0],
            'the skip link should be the first tab stop, not the second',
        );
    }

    #[DataProvider('pages')]
    public function testItPointsAtSomethingOnThePage(string $path): void
    {
        $html = $this->page($path);

        self::assertSame(
            1,
            preg_match('/<a class="skip-link" href="#([^"]+)"/', $html, $link),
            'the page should carry exactly one skip link',
        );

        self::assertSame(
            1,
            substr_count($html, 'id="' . $link[1] . '"'),
            'the skip link points at #' . $link[1] . ', which the page should carry exactly once',
        );
    }

    /**
     * And the target can hold focus, so following the link moves the cursor.
     */
    #[DataProvider('pages')]
    public function testTheTargetCanHoldFocus(string $path): void
    {
        self::assertMatchesRegularExpression(
            '/<div class="page" id="main" tabindex="-1">/',
            $this->page($path),
        );
    }

    /**
     * The case that decided the target, asserted rather than left to a comment.
     *
     * The 404 has no <main>, and it still has somewhere for the link to go. If
     * this page ever gains one, moving the id onto it is still the wrong
     * change -- the homepage and the search results have none either.
     */
    public function testAPageWithNoMainElementStillHasSomewhereToSkipTo(): void
    {
        $html = $this->page('/nothing-here');

        self::assertStringNotContainsString('<main', $html, 'sanity: this page opens no landmark');
        self::assertStringContainsString('id="main"', $html);
    }

    /**
     * Off-screen until it is focused, and it must not move the page when it
     * arrives.
     *
     * Asserted against the stylesheet the way FooterRenderTest asserts its
     * focus rings: what the render can show is the markup, and an unstyled
     * `.skip-link` is a visible link at the top of every page in the app --
     * which is not a failure anything else here would notice.
     */
    public function testTheLinkIsStyledOffScreenUntilItTakesFocus(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../frontend/css/main.css');

        self::assertMatchesRegularExpression(
            '/\.skip-link \{[^}]*position: fixed;/',
            $css,
            'out of flow, so taking focus costs no layout',
        );

        self::assertMatchesRegularExpression(
            '/\.skip-link \{[^}]*transform: translateY\(/',
            $css,
            'hidden by being moved off the top edge, not by display: none -- which is not focusable',
        );

        self::assertMatchesRegularExpression(
            '/\.skip-link:focus \{\s*transform: none;/',
            $css,
            'and brought back when it takes focus',
        );
    }

    /**
     * One page, rendered the way its controller renders it.
     *
     * Both of these need no database: /about is handed its prose, and the 404
     * has no content of its own at all.
     */
    private function page(string $path): string
    {
        Routes::setCurrentPage($path);

        [$template, $context] = $path === '/about'
            ? ['about/view.html.twig', [
                'breadcrumbs' => Breadcrumbs::trail($path, 'About'),
                'readme_html' => '<h2>A heading</h2><p>Some prose.</p>',
                'updated_at' => null,
                'repo_url' => 'https://example.invalid/repo',
            ]]
            : ['error/404-not-found.html.twig', []];

        return new TwigRenderer()->render($template, $context + [
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);
    }
}
