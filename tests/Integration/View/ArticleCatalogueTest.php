<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\View;

use TripBuilder\Config;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Routes;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\TwigRenderer;

/**
 * The real articles, rendered.
 *
 * HelpRenderTest used to do this by driving itself off the catalogue in
 * config/common/help.php. Articles are rows now, and a data provider is static
 * and runs before setUp(), so that suite supplies its own fixture articles and
 * proves the template's shape instead. What it stopped proving is that the
 * articles this site actually publishes render at all — which is this file.
 *
 * The gap being closed is not hypothetical. Until the prose moves into the
 * database too, an article is two things in two places: a row, and a template
 * named after its slug. A row with no template renders HelpController's catch
 * block — the words "Something went wrong while loading this page" where the
 * page should be — and nothing else would notice.
 */
final class ArticleCatalogueTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
    }

    /**
     * Every article on offer has prose to show.
     *
     * The second catalogue, asserted rather than assumed. Deleting a template
     * or adding a row without one both fail here.
     */
    public function testEveryArticleHasAProseTemplate(): void
    {
        $missing = [];

        foreach (array_keys($this->articles()) as $slug) {
            $path = dirname(__DIR__, 3) . '/frontend/template/help/articles/' . $slug . '.html.twig';

            if (!is_file($path)) {
                $missing[] = $slug;
            }
        }

        self::assertSame([], $missing, 'these articles are offered with no prose behind them');
    }

    /**
     * And no template is left behind with no article to reach it.
     *
     * The other direction: prose nobody can get to is prose nobody maintains,
     * and it would go on passing PromisesTest while saying anything at all.
     */
    public function testEveryProseTemplateHasAnArticle(): void
    {
        $slugs = array_keys($this->articles());
        $orphans = [];

        foreach (glob(dirname(__DIR__, 3) . '/frontend/template/help/articles/*.html.twig') ?: [] as $file) {
            $slug = basename($file, '.html.twig');

            if (!in_array($slug, $slugs, true)) {
                $orphans[] = $slug;
            }
        }

        self::assertSame([], $orphans, 'these templates are unreachable');
    }

    /**
     * Each article renders its own prose, opening with a heading.
     *
     * The assertion HelpRenderTest carried against the config catalogue: what
     * stands between a slug with no template and a page that renders the
     * controller's catch block.
     */
    public function testEveryArticleRendersItsOwnProse(): void
    {
        foreach ($this->articles() as $slug => $article) {
            $html = $this->page($slug);

            self::assertMatchesRegularExpression(
                '#<div class="article__body">\s*<h2>#s',
                $html,
                $slug . ' should open its body with a heading',
            );
            self::assertStringNotContainsString(
                'Something went wrong',
                $html,
                $slug . ' rendered the controller\'s catch block',
            );
        }
    }

    /**
     * The stored summary is both the lead and the meta description.
     *
     * One sentence in one place, which is what the column was for. Two copies
     * of it are two chances for them to stop agreeing.
     */
    public function testTheStoredSummaryIsBothTheDescriptionAndTheLead(): void
    {
        foreach ($this->articles() as $slug => $article) {
            $html = $this->page($slug);

            self::assertSame(
                1,
                preg_match('#<p class="article__lead">(.*?)</p>#s', $html, $lead),
                $slug . ' should have a lead',
            );
            self::assertSame(
                $article['summary'],
                html_entity_decode(trim((string) preg_replace('/\s+/', ' ', $lead[1])), ENT_QUOTES | ENT_HTML5),
                $slug . ': the lead should be the stored summary',
            );
            self::assertStringContainsString(
                'name="description" content="' . htmlspecialchars($article['summary'], ENT_QUOTES),
                $html,
                $slug . ': the description should be the stored summary',
            );
        }
    }

    /**
     * Every link in the prose points at an article that exists.
     *
     * Three of the five link to a sibling. This used to be checked with
     * `Routes::resolve()`, which the loose `#^/help/[A-Za-z-]+$#` pattern
     * satisfies for any word at all — so a typo in a cross-link passed. With
     * rows there is something real to check against.
     */
    public function testEveryHelpLinkInTheProseNamesAnArticle(): void
    {
        $known = $this->articles();

        foreach (array_keys($known) as $slug) {
            $html = $this->page($slug);
            $body = (string) preg_replace('#^.*<div class="article__body">#s', '', $html);
            $body = (string) preg_replace('#</div>.*$#s', '', $body);

            preg_match_all('#href="(/help/[^"]+)"#', $body, $links);

            foreach (array_unique($links[1]) as $href) {
                self::assertArrayHasKey(
                    substr($href, strlen('/help/')),
                    $known,
                    $slug . ' links ' . $href . ', which names no article',
                );
            }
        }
    }

    /** @return array<string, array{title: string, short: ?string, icon: string, summary: string}> */
    private function articles(): array
    {
        $articles = new ArticleRepository($this->connection())->all();

        self::assertNotEmpty($articles, 'no articles on the table to test');

        return $articles;
    }

    /**
     * One article page, built the way HelpController builds it.
     */
    private function page(string $slug): string
    {
        $articles = $this->articles();
        $more = [];

        foreach (array_diff_key($articles, [$slug => null]) as $other => $article) {
            $more[] = $article + ['slug' => $other, 'url' => '/help/' . $other];
        }

        Routes::setCurrentPage('/help/' . $slug);

        return new TwigRenderer()->render('help/view.html.twig', [
            'breadcrumbs' => Breadcrumbs::trail('/help/' . $slug, $articles[$slug]['title']),
            'article' => $articles[$slug] + ['slug' => $slug],
            'more' => $more,
            'verdict' => ['slug' => $slug, 'votes' => 0, 'yes' => 0, 'shown' => false, 'mine' => null],
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);
    }
}
