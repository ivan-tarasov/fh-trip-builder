<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Routes;
use TripBuilder\View\LayoutData;
use TripBuilder\View\TwigRenderer;

/**
 * The footer, which is on every page and is drawn from config.
 *
 * Three things here can break without anybody noticing on the page they were
 * working on. The link columns come from `site.footer-columns`, so a column
 * renamed in config and not in the partial silently disappears. The navigation
 * column is filtered by `enabled` and `footer`, and that filter exists because
 * the footer once shipped links to routes that were not routes -- a 404 on
 * every page of the site. And the whole file also carries every script tag and
 * closes the document, so a restructure that drops them takes the search form,
 * the calendar and the back-to-top button with it and leaves no error behind.
 */
final class FooterRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');

        // The footer reads a one-shot notice out of the session. There is no
        // session in a CLI test, and an unset superglobal would make the read
        // itself the thing under test.
        $_SESSION = [];
    }

    /**
     * The footer is rendered as a fragment, so the three figures it prints have
     * to be handed over: they normally arrive through renderPage()'s context
     * merge, which render() does not do.
     */
    private function render(string $page): string
    {
        Routes::setCurrentPage($page);

        $html = new TwigRenderer()->render('partials/footer.html.twig', [
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);

        // Entities decoded, tags left alone, so a heading can be asserted the
        // way it is written in config. "Help & tips" reaches the page as
        // `Help &amp; tips`, and a test spelling it that way would be matching
        // Twig's escaping rather than the heading.
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    }

    public function testEveryConfiguredColumnIsDrawn(): void
    {
        $html = $this->render('/airlines');

        foreach (Config::get('site.footer-columns') as $column) {
            // A column that draws itself from the database is covered below;
            // this one is about the config-driven five.
            if (!isset($column['links'])) {
                continue;
            }

            self::assertStringContainsString(
                '>' . $column['title'] . '</h2>',
                $html,
                $column['title'] . ' should head a column',
            );

            foreach ($column['links'] as $label => $url) {
                self::assertStringContainsString('href="' . $url . '"', $html, $url . ' should be linked');
                self::assertStringContainsString('>' . $label . '</a>', $html, $label . ' should be named');
            }
        }
    }

    /**
     * The data-driven column appears when it has something and not when it does
     * not.
     *
     * Written to hold either way round, because which one this suite sees is
     * decided by whether a database happens to be reachable -- and the answer
     * that matters is that an empty result takes the heading with it rather
     * than leaving one standing over nothing.
     */
    public function testADataDrivenColumnFollowsItsData(): void
    {
        $columns = array_filter(
            Config::get('site.footer-columns'),
            static fn(array $column): bool => isset($column['source']),
        );

        self::assertNotEmpty($columns, 'one column should be drawn from the data');

        $html = $this->render('/airlines');

        foreach ($columns as $column) {
            $cities = new LayoutData()->mostSearchedCities($column['count'] ?? 5);
            $heading = '>' . $column['title'] . '</h2>';

            if ($cities === []) {
                self::assertStringNotContainsString($heading, $html, 'an empty column should not be headed');

                continue;
            }

            self::assertStringContainsString($heading, $html);

            foreach ($cities as $name => $url) {
                self::assertStringContainsString('href="' . $url . '"', $html);
                self::assertStringContainsString('>' . $name . '</a>', $html);
            }
        }
    }

    /**
     * A "more" link only where there is somewhere for it to go. Four of the six
     * columns lead to pages that do not exist yet, and offering to show more of
     * them is a dead end offering more dead ends.
     */
    public function testMoreLinksAppearOnlyWhereConfigured(): void
    {
        $html = $this->render('/airlines');

        $configured = 0;

        foreach (Config::get('site.footer-columns') as $column) {
            if (!isset($column['more'])) {
                continue;
            }

            $configured++;
            self::assertStringContainsString('>' . $column['more']['text'] . '</a>', $html);
        }

        self::assertSame(
            $configured,
            substr_count($html, 'footer__more'),
            'every more-link is configured, and every configured more-link is drawn',
        );
    }

    /**
     * The only part of the footer that differs between pages.
     */
    public function testDestinationsAreOnTheHomepageAndNowhereElse(): void
    {
        self::assertStringContainsString('footer__destinations', $this->render('/'));

        foreach (['/airlines', '/my/bookings', '/search/YUL151026LHRY1', '/nothing-here'] as $page) {
            self::assertStringNotContainsString(
                'footer__destinations',
                $this->render($page),
                $page . ' should not carry the destinations block',
            );
        }
    }

    public function testEveryConfiguredDestinationIsDrawn(): void
    {
        $html = $this->render('/');

        foreach (Config::get('site.footer-destinations') as $place) {
            self::assertStringContainsString('>' . $place['city'] . '</span>', $html);
            self::assertStringContainsString('href="' . $place['url'] . '"', $html);
        }
    }

    /**
     * The filter that keeps the navigation column honest.
     *
     * `/software-tests/` is `enabled => false` and `/currency/` is
     * `footer => false`; neither is a route, and both used to ship as links.
     */
    public function testNavigationListsOnlyPagesThatExist(): void
    {
        $html = $this->render('/airlines');

        foreach (Config::get('site.main-menu') as $url => $item) {
            $shown = $item['enabled'] && ($item['footer'] ?? true);
            $needle = 'href="' . $url . '"';

            $shown
                ? self::assertStringContainsString($needle, $html, $url . ' should be listed')
                : self::assertStringNotContainsString($needle, $html, $url . ' should not be listed');
        }
    }

    /**
     * The one that cannot be talked round by editing config.
     *
     * The test above reads the same flags it asserts on, so flipping `enabled`
     * flips the expectation with it and the mutation goes unnoticed -- which is
     * exactly the change that shipped the original bug. This asks the router
     * instead: whatever the navigation column links to has to be a page that
     * resolves. The six curated columns are deliberately not held to this; they
     * name pages that are still to be built and answer 404 meanwhile.
     */
    public function testNavigationOnlyLinksToRoutesThatResolve(): void
    {
        $html = $this->render('/airlines');

        preg_match('/<ul[^>]*aria-labelledby="footer-col-nav"[^>]*>(.*?)<\/ul>/s', $html, $column);
        self::assertNotEmpty($column, 'the navigation column should be findable');

        preg_match_all('/href="([^"]+)"/', $column[1], $links);
        self::assertNotEmpty($links[1], 'the navigation column should have links');

        foreach ($links[1] as $href) {
            // rtrim, because the menu spells its paths with a trailing slash and
            // Request::path() takes it off before the router ever sees it.
            self::assertNotNull(
                Routes::resolve(rtrim($href, '/') ?: '/'),
                $href . ' is linked in the footer but is not a route',
            );
        }
    }

    /**
     * City links are held to the standard the other five columns are not.
     *
     * Airlines, Directions, Countries and Help name pages that are still to be
     * built and answer 404 on purpose. Cities is no longer one of those: the
     * pages exist, so a link into them that does not resolve is a bug and not a
     * plan. This covers the curated destinations block; the column beside it is
     * built from the database and cannot name a city that is not there.
     */
    public function testEveryCityLinkResolves(): void
    {
        $html = $this->render('/');

        preg_match_all('#href="(/cities/[^"]+)"#', $html, $links);

        self::assertNotEmpty($links[1], 'the footer should link to cities');

        foreach (array_unique($links[1]) as $href) {
            self::assertNotNull(
                Routes::resolve($href),
                $href . ' is linked in the footer but is not a route',
            );

            // The route pattern alone would pass /cities/YMQ, which is how
            // these were written before the pages existed and is now a 404.
            self::assertMatchesRegularExpression(
                '#^/cities/[a-z0-9]+(?:-[a-z0-9]+)*-[a-z0-9]{3}$#',
                $href,
                $href . ' is not a canonical city address',
            );
        }
    }

    /**
     * A new tab must not be handed a reference back to this one.
     */
    public function testExternalLinksCannotReachBack(): void
    {
        $html = $this->render('/');

        preg_match_all('/<a\b[^>]*target="_blank"[^>]*>/', $html, $matches);

        self::assertNotEmpty($matches[0], 'the footer does link out');

        foreach ($matches[0] as $tag) {
            self::assertStringContainsString('rel="', $tag, 'no rel on: ' . $tag);
            self::assertStringContainsString('noopener', $tag, 'no noopener on: ' . $tag);
        }
    }

    /**
     * The partial is the tail of the document as well as the footer. Nothing
     * else loads these, and nothing else closes the page.
     */
    public function testTheDocumentIsClosedAndTheScriptsAreLoaded(): void
    {
        $html = $this->render('/');

        foreach (['/datepicker.js', '/global.js', 'bootstrap.bundle.min.js'] as $script) {
            self::assertStringContainsString($script, $html, $script . ' should still be loaded');
        }

        self::assertStringContainsString('</body>', $html);
        self::assertStringContainsString('</html>', $html);
    }

    /**
     * The notice a form post leaves behind is shown once and then forgotten.
     * Left in place it would reappear on the next page and on every refresh.
     */
    public function testTheSubscribeNoticeIsReadOnceAndCleared(): void
    {
        $_SESSION['subscribe_notice'] = ['tone' => 'good', 'message' => 'Done.'];

        $layout = new LayoutData();

        self::assertSame(['tone' => 'good', 'message' => 'Done.'], $layout->subscribeNotice());
        self::assertNull($layout->subscribeNotice());
        self::assertArrayNotHasKey('subscribe_notice', $_SESSION);
    }

    public function testAMalformedNoticeIsIgnored(): void
    {
        $_SESSION['subscribe_notice'] = 'not an array';

        self::assertNull(new LayoutData()->subscribeNotice());
    }
}
