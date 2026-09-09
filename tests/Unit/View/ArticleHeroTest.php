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
 * The band a help or legal page opens with.
 *
 * Three things here would go wrong quietly, and each has a test below.
 *
 * A page draws the band by saying so twice: `{% block hero %}` tells the
 * layout not to draw the breadcrumb strip, and `'hero': true` tells the
 * article partial to draw the band with the trail inside it. Change one and
 * the page either loses its trail entirely or shows two -- neither of which
 * throws, and the second is only visible if you look.
 *
 * `partials/article.html.twig` is embedded by five templates, so the band had
 * to be opt-in: /about is a rendered README whose heading belongs on the sheet
 * with the prose. That opt-out is asserted, because a default flipped the
 * other way would put a band on a page nobody asked to change.
 *
 * And the band must not be called `hero`. main.css makes the header sticky on
 * any page containing `.hero`, matched on the element rather than on a class
 * the page carries, so taking that name would change the header's behaviour on
 * every article page -- which is the one thing the brief ruled out.
 *
 * A real trail is passed throughout. HelpRenderTest and LegalRenderTest both
 * hand these templates an empty one, which renders no breadcrumbs at all, so
 * neither of them can see any of this.
 */
final class ArticleHeroTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
    }

    /** @return iterable<string, array{string}> */
    public static function bandedPages(): iterable
    {
        yield 'a help article' => ['/help/baggage'];
        yield 'the help hub' => ['/help'];
        yield 'a legal document' => ['/privacy'];
    }

    #[DataProvider('bandedPages')]
    public function testAHelpOrLegalPageOpensWithABand(string $page): void
    {
        $html = $this->page($page);

        self::assertStringContainsString('<div class="article__hero">', $html);
        self::assertStringContainsString('class="article article--hero"', $html);
    }

    /**
     * The README page shares the partial and keeps the old shape.
     */
    public function testTheReadmePageDoesNotGetABand(): void
    {
        $html = $this->page('/about');

        self::assertStringNotContainsString('article__hero', $html);
        self::assertStringNotContainsString('article--hero', $html);
        self::assertStringContainsString('class="article"', $html);
    }

    /**
     * One trail per page, wherever it is drawn.
     *
     * The guard on the two-touchpoint pairing, and the reason it is safe to
     * have two: filling the block without passing `hero` leaves the page with
     * no trail at all, and passing `hero` without filling the block draws two.
     * Both are caught here.
     */
    #[DataProvider('everyArticleFamilyPage')]
    public function testExactlyOneTrailIsDrawn(string $page): void
    {
        self::assertSame(
            1,
            substr_count($this->page($page), '<nav class="breadcrumbs'),
            $page . ' should draw the breadcrumb trail exactly once',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function everyArticleFamilyPage(): iterable
    {
        yield 'a help article' => ['/help/baggage'];
        yield 'the help hub' => ['/help'];
        yield 'a legal document' => ['/privacy'];
        yield 'the readme page' => ['/about'];
    }

    /**
     * And on a banded page it is inside the band, on the dark colours.
     *
     * Where it does the job the reference gives an "all articles" eyebrow, and
     * where it has to be: left in the layout's strip it would show a band of
     * page background between the header and the band below it.
     */
    #[DataProvider('bandedPages')]
    public function testTheTrailSitsInsideTheBandAndTakesItsColours(string $page): void
    {
        $html = $this->page($page);
        $band = $this->band($html);

        self::assertStringContainsString('<nav class="breadcrumbs breadcrumbs--on-dark"', $band);
        self::assertStringContainsString('Home', $band, 'the trail should be in the band');
    }

    /**
     * Without a band the trail keeps the light colours and stays out of <main>.
     */
    public function testWithoutABandTheTrailIsUnchanged(): void
    {
        $html = $this->page('/about');

        self::assertStringContainsString('<nav class="breadcrumbs" aria-label="Breadcrumb">', $html);
        self::assertStringNotContainsString('breadcrumbs--on-dark', $html);
        self::assertLessThan(
            strpos($html, '<main'),
            strpos($html, '<nav class="breadcrumbs"'),
            'the layout draws it above <main>',
        );
    }

    /**
     * The heading keeps the class spelled exactly as it was.
     *
     * LegalRenderTest matches `<h1 class="article__title">` with its closing
     * quote, so a modifier alongside it would fail the legal suite without
     * changing anything a reader sees. The band styles the heading by descent
     * instead, and this says so where somebody would be tempted.
     */
    #[DataProvider('bandedPages')]
    public function testTheHeadingCarriesNoExtraClass(string $page): void
    {
        $html = $this->page($page);

        self::assertStringContainsString('<h1 class="article__title">', $html);
        self::assertSame(1, substr_count($html, '<h1 '), 'one heading, in one place');
    }

    /**
     * The band is not called `hero`, and this is why.
     *
     * `body:has(.hero) header#top { position: sticky }` in main.css keys the
     * sticky header off the element. A class of exactly `hero` anywhere on
     * these pages makes the header sticky here, which the brief ruled out --
     * and nothing would fail, it would just start following the reader down
     * the page.
     */
    #[DataProvider('bandedPages')]
    public function testTheBandDoesNotBorrowTheHomepageHeroClass(string $page): void
    {
        $classes = [];

        preg_match_all('/class="([^"]*)"/', $this->page($page), $matches);

        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                $classes[] = $class;
            }
        }

        self::assertNotEmpty($classes, 'sanity: the page has classes to read');

        // Tokens, not a substring. The first version of this looked for
        // `\bhero\b` and failed on `article--hero`: a hyphen is a word
        // boundary, so the guard caught the very class it exists to allow.
        // `:has(.hero)` matches a class of exactly `hero` and nothing else,
        // which is what to look for.
        self::assertNotContains(
            'hero',
            $classes,
            'a class of exactly `hero` here makes the header sticky -- see main.css:3543',
        );
    }

    /**
     * The band's contents, from `<div class="article__hero">` to its close.
     */
    private function band(string $html): string
    {
        $open = strpos($html, '<div class="article__hero">');

        self::assertNotFalse($open, 'no band to read');

        $end = strpos($html, '<div class="container article__layout"', $open);

        self::assertNotFalse($end, 'the sheet should follow the band');

        return substr($html, $open, $end - $open);
    }

    /**
     * One page of an article family, built the way its controller builds it.
     */
    private function page(string $path): string
    {
        Routes::setCurrentPage($path);

        [$template, $context] = $this->contextFor($path);

        return new TwigRenderer()->render($template, $context + [
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function contextFor(string $path): array
    {
        if ($path === '/about') {
            return ['about/view.html.twig', [
                'breadcrumbs' => Breadcrumbs::trail($path, 'About'),
                'readme_html' => '<h2>A heading</h2><p>Some prose.</p>',
                'updated_at' => null,
                'repo_url' => 'https://example.invalid/repo',
            ]];
        }

        if ($path === '/help') {
            /** @var array<string, array<string, mixed>> $articles */
            $articles = Config::get('help.articles', []);
            $listed = [];

            foreach ($articles as $slug => $article) {
                $listed[] = $article + ['slug' => $slug, 'url' => '/help/' . $slug];
            }

            return ['help/index.html.twig', [
                'breadcrumbs' => Breadcrumbs::trail($path),
                'articles' => $listed,
            ]];
        }

        if (str_starts_with($path, '/help/')) {
            $slug = substr($path, strlen('/help/'));
            /** @var array<string, array<string, mixed>> $articles */
            $articles = Config::get('help.articles', []);
            $more = [];

            foreach (array_diff_key($articles, [$slug => null]) as $other => $article) {
                $more[] = $article + ['slug' => $other, 'url' => '/help/' . $other];
            }

            return ['help/view.html.twig', [
                'breadcrumbs' => Breadcrumbs::trail($path, (string) $articles[$slug]['title']),
                'article' => $articles[$slug] + ['slug' => $slug],
                'more' => $more,
                'verdict' => ['slug' => $slug, 'votes' => 0, 'yes' => 0, 'shown' => false, 'mine' => null],
            ]];
        }

        $slug = ltrim($path, '/');
        /** @var array<string, array<string, mixed>> $documents */
        $documents = Config::get('legal.documents', []);
        $more = [];

        foreach (array_diff_key($documents, [$slug => null]) as $other => $document) {
            $more[] = $document + ['slug' => $other, 'url' => '/' . $other];
        }

        return ['legal/view.html.twig', [
            'breadcrumbs' => Breadcrumbs::trail($path, (string) $documents[$slug]['title']),
            'document' => $documents[$slug] + ['slug' => $slug],
            'more' => $more,
        ]];
    }
}
