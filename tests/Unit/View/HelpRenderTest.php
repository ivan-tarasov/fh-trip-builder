<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Party;
use TripBuilder\Routes;
use TripBuilder\View\TwigRenderer;

/**
 * The help articles, and the hub that lists them.
 *
 * The only page family here that is prose rather than rows, which changes what
 * can go wrong with it. There is no query to fail; what fails is an article
 * added to config/common/help.php with no template beside it, or a link in the
 * prose to a page that does not exist. Both would reach a reader as a working
 * page -- the first as "Something went wrong", the second as a 404 one click
 * away -- and neither would fail anything else in this suite.
 *
 * Every case is driven from the article index rather than from a list written
 * here, so a sixth article is covered by existing.
 */
final class HelpRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');

        // The footer reads a one-shot notice out of the session, as
        // FooterRenderTest explains.
        $_SESSION = [];
    }

    /** @return array<string, array<string, mixed>> */
    private static function articles(): array
    {
        /** @var array<string, array<string, mixed>> $articles */
        $articles = Config::get('help.articles', []);

        return $articles;
    }

    /**
     * The whole page, with the three figures the footer needs.
     *
     * They normally arrive through renderPage()'s context merge, which
     * render() does not do.
     *
     * @param array<string, mixed> $context
     */
    private function render(string $template, string $page, array $context): string
    {
        Routes::setCurrentPage($page);

        return new TwigRenderer()->render($template, $context + [
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);
    }

    /**
     * One article page, built the way HelpController builds it.
     */
    private function article(string $slug): string
    {
        $articles = self::articles();
        $more = [];

        foreach (array_diff_key($articles, [$slug => null]) as $key => $article) {
            $more[] = $article + ['slug' => $key, 'url' => '/help/' . $key];
        }

        return $this->render('help/view.html.twig', '/help/' . $slug, [
            'breadcrumbs' => [],
            'article' => $articles[$slug] + ['slug' => $slug],
            'more' => $more,
        ]);
    }

    /**
     * One case per article in the index.
     *
     * Config is loaded here as well as in setUp(): a data provider is static
     * and runs before the first test does, so the index is not read yet.
     *
     * @return list<array{string}>
     */
    public static function articleProvider(): array
    {
        new Config('common');

        return array_map(static fn(string $slug): array => [$slug], array_keys(self::articles()));
    }

    /**
     * An article in the index has prose to show.
     *
     * The template is found by name rather than named in the config, so this is
     * what stands between a typo in a key and a page that renders the
     * controller's catch block.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('articleProvider')]
    public function testEveryArticleRendersItsOwnProse(string $slug): void
    {
        $html = $this->article($slug);

        self::assertStringContainsString('article__body', $html);
        // A heading of its own, so an article that rendered an empty body
        // cannot pass on the strength of the shell around it.
        self::assertMatchesRegularExpression('#<div class="article__body">.*?<h2>#s', $html);
    }

    /**
     * One sentence in one place: the summary is the description and the lead.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('articleProvider')]
    public function testTheSummaryIsBothTheDescriptionAndTheLead(string $slug): void
    {
        $html = $this->article($slug);
        $summary = (string) self::articles()[$slug]['summary'];

        preg_match('#<p class="article__lead">(.*?)</p>#s', $html, $lead);
        self::assertNotEmpty($lead, 'the lead should be findable');

        // Collapsed, because the template wraps the sentence across lines and
        // what is being asserted is the sentence rather than the indentation.
        self::assertSame(
            $summary,
            html_entity_decode(
                (string) preg_replace('/\s+/', ' ', trim($lead[1])),
                ENT_QUOTES | ENT_HTML5,
            ),
        );

        self::assertStringContainsString(
            'name="description" content="' . htmlspecialchars($summary, ENT_QUOTES),
            $html,
        );
    }

    /**
     * The other four, and not this one.
     *
     * An article has no data of its own to link out with, so the siblings are
     * the whole of it -- and an article listing itself is a link back to the
     * page it is on.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('articleProvider')]
    public function testMoreHelpNamesTheOtherArticlesAndNotThisOne(string $slug): void
    {
        $html = $this->article($slug);

        preg_match('#<aside class="article__aside".*?</aside>#s', $html, $aside);
        self::assertNotEmpty($aside, 'the more-help list should be findable');

        preg_match_all('#href="(/help/[^"]+)"#', $aside[0], $links);

        self::assertSame(
            array_values(array_diff(array_keys(self::articles()), [$slug])),
            array_map(static fn(string $href): string => substr($href, strlen('/help/')), $links[1]),
        );
    }

    /**
     * Every link in the prose goes somewhere.
     *
     * Asked of the router, not of a list here. The prose points at My bookings,
     * the airline directory and its own siblings, and a mistyped one of those
     * is a 404 reached from a page that looks finished.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('articleProvider')]
    public function testEveryLinkInTheProseResolves(string $slug): void
    {
        preg_match('#<div class="article__body">.*?</div>#s', $this->article($slug), $body);
        self::assertNotEmpty($body, 'the article body should be findable');

        preg_match_all('#href="([^"]+)"#', $body[0], $links);

        foreach (array_unique($links[1]) as $href) {
            self::assertNotNull(
                Routes::resolve(rtrim($href, '/') ?: '/'),
                $href . ' is linked from /help/' . $slug . ' but is not a route',
            );
        }
    }

    /**
     * The prose quotes the numbers the prices are worked out with.
     *
     * Written out, this page would be the last place anybody looked after
     * tuning a share -- so it prints them, and this is what says so. A share
     * changed in Party without the page following it can only fail here.
     */
    public function testTheChildAndInfantSharesComeFromParty(): void
    {
        $html = $this->article('flying-with-children');
        $shares = Party::shares();

        self::assertStringContainsString(
            '<strong>' . round($shares['child_fare'] * 100) . '%</strong>',
            $html,
        );
        self::assertStringContainsString(
            '<strong>' . round($shares['infant_fare'] * 100) . '%</strong>',
            $html,
        );
    }

    /**
     * The seat limit is the one the form enforces.
     */
    public function testTheSeatLimitComesFromParty(): void
    {
        self::assertStringContainsString(
            Party::MAX_SEATS . ' seats at most',
            $this->article('flying-with-children'),
        );
    }

    /**
     * The hub names all of them, with the line that tells them apart.
     */
    public function testTheHubListsEveryArticle(): void
    {
        $articles = [];

        foreach (self::articles() as $slug => $article) {
            $articles[] = $article + ['slug' => $slug, 'url' => '/help/' . $slug];
        }

        $html = $this->render('help/index.html.twig', '/help', ['articles' => $articles]);

        foreach ($articles as $article) {
            self::assertStringContainsString('href="' . $article['url'] . '"', $html);
            self::assertStringContainsString(
                htmlspecialchars((string) $article['title'], ENT_QUOTES),
                $html,
            );
            self::assertStringContainsString(
                htmlspecialchars((string) $article['summary'], ENT_QUOTES),
                $html,
            );
        }
    }
}
