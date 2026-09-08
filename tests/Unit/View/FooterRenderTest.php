<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Helper;
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
            // Asked for by its own source, through the same dispatcher the
            // template calls. This used to ask mostSearchedCities() for every
            // data-driven column, which was right while there was one -- with
            // two it checked the routes column against the city links, and
            // passed, because those links are on the page in the column next
            // door. Scoping below is the other half of that fix.
            $links = new LayoutData()->footerLinks($column['source'], $column['count'] ?? 5);
            $heading = '>' . $column['title'] . '</h2>';

            if ($links === []) {
                self::assertStringNotContainsString($heading, $html, 'an empty column should not be headed');

                continue;
            }

            self::assertStringContainsString($heading, $html);

            $own = self::columnMarkup($html, $column['title']);

            foreach ($links as $name => $url) {
                self::assertStringContainsString('href="' . $url . '"', $own, $url . ' should be in ' . $column['title']);
                self::assertStringContainsString('>' . $name . '</a>', $own, $name . ' should be in ' . $column['title']);
            }
        }
    }

    /**
     * One column's own list, so an assertion about it cannot be satisfied by
     * the column beside it.
     *
     * Cut from the heading to the end of the list that follows it, which is the
     * shape partials/footer/column.html.twig renders: an h2 and then one ul.
     */
    private static function columnMarkup(string $html, string $title): string
    {
        $at = strpos($html, '>' . $title . '</h2>');
        self::assertNotFalse($at, $title . ' should head a column');

        $end = strpos($html, '</ul>', $at);
        self::assertNotFalse($end, $title . ' should be followed by a list');

        return substr($html, $at, $end - $at);
    }

    /**
     * A "more" link only where there is somewhere for it to go. Two of the six
     * columns have none, for two different reasons: Help & tips leads to pages
     * that do not exist yet, and Directions leads to real ones that no index
     * could list -- 42,578 city pairs can be flown nonstop, so there is no
     * /routes page and there should not be one.
     *
     * Counted against the columns that were actually drawn, not against every
     * column configured: a data-driven column takes its more-link with it when
     * it has no data, which is what happens here whenever no database is
     * reachable.
     */
    public function testMoreLinksAppearOnlyWhereConfigured(): void
    {
        $html = $this->render('/airlines');

        $drawn = 0;

        foreach (Config::get('site.footer-columns') as $column) {
            if (!isset($column['more'])) {
                continue;
            }

            // The column exists on the page only if it had links to show.
            if (!str_contains($html, '>' . $column['title'] . '</h2>')) {
                continue;
            }

            $drawn++;
            self::assertStringContainsString('>' . $column['more']['text'] . '</a>', $html);
        }

        self::assertGreaterThan(0, $drawn, 'at least one more-link should be drawn');
        self::assertSame(
            $drawn,
            substr_count($html, 'footer__more'),
            'every more-link drawn belongs to a column that was drawn',
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
     * resolves. Every curated column is now held to the same standard in a test
     * of its own -- Help & tips was the last one naming pages that did not
     * exist.
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
     * City links are held to a standard the whole footer now meets.
     *
     * Cities was the first column to stop naming pages that were still to be
     * built: the pages exist, so a link into them that does not resolve is a bug
     * and not a plan. This covers the curated destinations block; the column
     * beside it is built from the database and cannot name a city that is not
     * there.
     */
    public function testEveryCityLinkResolves(): void
    {
        $html = $this->render('/');

        preg_match_all('#href="(/city/[^"]+)"#', $html, $links);

        self::assertNotEmpty($links[1], 'the footer should link to cities');

        foreach (array_unique($links[1]) as $href) {
            self::assertNotNull(
                Routes::resolve($href),
                $href . ' is linked in the footer but is not a route',
            );

            // The route pattern alone would pass /city/YMQ, which is how
            // these were written before the pages existed and is now a 404.
            self::assertMatchesRegularExpression(
                '#^/city/[a-z0-9]+(?:-[a-z0-9]+)*-[a-z0-9]{3}$#',
                $href,
                $href . ' is not a canonical city address',
            );
        }
    }

    /**
     * And so are the country links, for the same reason.
     *
     * These were curated placeholders until the pages existed. They are still
     * curated -- nothing counts how often a country is searched for -- but they
     * are no longer placeholders, so a typo in one is now a 404 on every page
     * of the site rather than a link to a page that was always going to 404.
     */
    public function testEveryCountryLinkResolves(): void
    {
        $html = $this->render('/');

        preg_match_all('#href="(/country/[^"]+)"#', $html, $links);

        self::assertNotEmpty($links[1], 'the footer should link to countries');

        foreach (array_unique($links[1]) as $href) {
            self::assertNotNull(
                Routes::resolve($href),
                $href . ' is linked in the footer but is not a route',
            );

            // Two characters on the end, not three: the route pattern would
            // pass a city address under /country/ and the controller would
            // answer 404.
            self::assertNotNull(
                Helper::placeCode(substr($href, strlen('/country/')), 2),
                $href . ' is not a canonical country address',
            );
        }
    }

    /**
     * And the airport links, which changed shape when the pages arrived.
     *
     * These were /airport/YUL, and the route pattern still accepts that -- it
     * is deliberately loose about the half in front of the code. What turns a
     * bare code away is the controller, so a link left in the old spelling
     * would pass every check except the only one that matters and 404 on every
     * page of the site.
     */
    public function testEveryAirportLinkResolves(): void
    {
        $html = $this->render('/');

        preg_match_all('#href="(/airport/[^"]+)"#', $html, $links);

        self::assertNotEmpty($links[1], 'the footer should link to airports');

        foreach (array_unique($links[1]) as $href) {
            self::assertNotNull(
                Routes::resolve($href),
                $href . ' is linked in the footer but is not a route',
            );

            self::assertNotNull(
                Helper::placeCode(substr($href, strlen('/airport/')), 3),
                $href . ' is not a canonical airport address',
            );
        }
    }

    /**
     * And the airline links, which changed shape the same way the airports did.
     *
     * Two characters on the end rather than three, because that is what an IATA
     * airline code is. These shipped as /airline/AC on the theory that a real
     * code would start working the day the page did; it did not, because an
     * address is a name and a code together. Every column has now learned that
     * the same way, which is why each has a test of its own.
     */
    public function testEveryAirlineLinkResolves(): void
    {
        $html = $this->render('/');

        preg_match_all('#href="(/airline/[^"]+)"#', $html, $links);

        self::assertNotEmpty($links[1], 'the footer should link to airlines');

        foreach (array_unique($links[1]) as $href) {
            self::assertNotNull(
                Routes::resolve($href),
                $href . ' is linked in the footer but is not a route',
            );

            self::assertNotNull(
                Helper::placeCode(substr($href, strlen('/airline/')), 2),
                $href . ' is not a canonical airline address',
            );
        }
    }

    /**
     * And the route links, which are the only ones nothing curates.
     *
     * Held to the standard the other columns are, and it matters more here:
     * every other column is a list somebody wrote and could check by eye, and
     * this one is whatever people have searched for. A search is recorded for
     * any pair anybody asked about and only the pairs you can fly nonstop have
     * a page, so the query is the only thing standing between this column and a
     * 404 on every page of the site.
     *
     * Written to hold either way round, like the data-driven column test above,
     * because whether this suite sees any of these depends on whether a
     * database is reachable. With none, the assertion that matters is that the
     * column took its heading with it.
     */
    public function testEveryRouteLinkResolves(): void
    {
        $html = $this->render('/');

        preg_match_all('#href="(/route/[^"]+)"#', $html, $links);

        if ($links[1] === []) {
            self::assertStringNotContainsString('>Directions</h2>', $html, 'an empty column should not be headed');

            return;
        }

        foreach (array_unique($links[1]) as $href) {
            self::assertNotNull(
                Routes::resolve($href),
                $href . ' is linked in the footer but is not a route',
            );

            // The route pattern would pass /route/YMQ-YTO, which is how these
            // were written before the pages existed. What turns that away is
            // RouteAddress::read(), so the shape is asserted here: two names
            // with the join between them, and no code on either end.
            self::assertMatchesRegularExpression(
                '#^/route/[a-z0-9]+(?:-[a-z0-9]+)*-to-[a-z0-9]+(?:-[a-z0-9]+)*$#',
                $href,
                $href . ' is not a canonical route address',
            );
        }
    }

    /**
     * The help column, which was the reason the exemption above existed.
     *
     * Five links on every page of the site, answering 404 on every one of them,
     * for as long as the footer has been written. They resolve now, and each is
     * checked against the article index as well as against the router: the
     * pattern would pass `/help/anything`, and what makes a slug real is being
     * one of the five words in config/common/help.php.
     */
    public function testEveryHelpLinkResolves(): void
    {
        $html = $this->render('/');

        preg_match_all('#href="(/help[^"]*)"#', $html, $links);

        self::assertNotEmpty($links[1], 'the help column should have links');

        /** @var array<string, array<string, mixed>> $articles */
        $articles = Config::get('help.articles', []);

        foreach (array_unique($links[1]) as $href) {
            self::assertNotNull(
                Routes::resolve($href),
                $href . ' is linked in the footer but is not a route',
            );

            if ($href === '/help') {
                continue;
            }

            self::assertArrayHasKey(
                substr($href, strlen('/help/')),
                $articles,
                $href . ' is linked in the footer but names no article',
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
