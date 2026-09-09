<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Routes;
use TripBuilder\View\TwigRenderer;

/**
 * Privacy, Terms and Cookies.
 *
 * Prose rather than rows, like the help articles, and the same three things can
 * go wrong quietly: a document added to config/common/legal.php with no
 * template beside it renders "Something went wrong", one with no route in
 * ENABLED_ROUTES is a page nothing can reach, and one the footer does not link
 * is a legal page hidden from the people it is for. None of those fails
 * anything else in this suite.
 *
 * Everything is driven from the document index, so a fourth document is
 * covered by existing.
 */
final class LegalRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');

        // The footer reads a one-shot notice out of the session, as
        // FooterRenderTest explains.
        $_SESSION = [];
    }

    public function testEveryDocumentRendersWithItsOwnTitleAndLead(): void
    {
        foreach (self::documents() as $slug => $document) {
            $html = $this->document($slug);

            self::assertSame(
                $document['title'],
                self::heading($html),
                $slug . ' should be headed by its title',
            );

            self::assertStringContainsString(
                (string) $document['summary'],
                self::decoded($html),
                $slug . ' should lead with its summary',
            );
        }
    }

    /**
     * The one that catches a config entry with nothing behind it.
     *
     * `legal/view.html.twig` builds the include path from the slug, so a
     * document listed with no template is a render error rather than a missing
     * section -- and it would be found by opening the page, which is exactly
     * what nobody does to a privacy page.
     */
    public function testEveryDocumentHasAPromiseOfProseBehindIt(): void
    {
        foreach (array_keys(self::documents()) as $slug) {
            self::assertFileExists(
                __DIR__ . '/../../../frontend/template/legal/documents/' . $slug . '.html.twig',
                $slug . ' is listed in config with no template',
            );
        }
    }

    /**
     * And the one that catches a page nothing can reach.
     *
     * A route here is also what puts the page in the sitemap and what lets
     * LayoutData::indexable() keep it, so an unrouted document is invisible
     * three ways over.
     */
    public function testEveryDocumentIsRoutedToTheLegalController(): void
    {
        foreach (array_keys(self::documents()) as $slug) {
            self::assertSame(
                'Legal@show',
                Routes::ENABLED_ROUTES['/' . $slug] ?? null,
                '/' . $slug . ' is listed in config with no route',
            );
        }
    }

    /**
     * The footer is the only way to these pages from anywhere else on the site.
     */
    public function testTheFooterLinksEveryDocument(): void
    {
        $html = $this->footer();

        foreach (self::documents() as $slug => $document) {
            self::assertStringContainsString('href="/' . $slug . '"', $html, $slug . ' is not linked');
            self::assertStringContainsString('>' . $document['title'] . '</a>', $html);
        }
    }

    /**
     * Each page offers the other two, and never itself.
     */
    public function testADocumentOffersItsSiblingsAndNotItself(): void
    {
        foreach (array_keys(self::documents()) as $slug) {
            $aside = self::aside($this->document($slug));

            self::assertStringNotContainsString('href="/' . $slug . '"', $aside, $slug . ' points at itself');

            foreach (array_keys(self::documents()) as $other) {
                if ($other !== $slug) {
                    self::assertStringContainsString('href="/' . $other . '"', $aside);
                }
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function documents(): array
    {
        /** @var array<string, array<string, mixed>> $documents */
        $documents = Config::get('legal.documents', []);

        return $documents;
    }

    /**
     * Entities decoded, so a title can be asserted the way config writes it.
     */
    private static function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    }

    /**
     * The <h1>'s own text, without the icon the shell puts inside it.
     */
    private static function heading(string $html): string
    {
        preg_match('#<h1 class="article__title">(.*?)</h1>#s', $html, $match);

        return trim(self::decoded(strip_tags($match[1] ?? '')));
    }

    /**
     * Just the sibling panel. Sliced, because the footer below it links these
     * same three pages -- the first version of this test read to the end of the
     * document and found the footer's own legal row.
     */
    private static function aside(string $html): string
    {
        $start = (int) strpos($html, 'more-legal-title');
        $end = (int) strpos($html, '</aside>', $start);

        return substr($html, $start, $end - $start);
    }

    /**
     * One legal page, built the way LegalController builds it.
     */
    private function document(string $slug): string
    {
        $documents = self::documents();
        $more = [];

        foreach (array_diff_key($documents, [$slug => null]) as $otherSlug => $other) {
            $more[] = $other + ['slug' => $otherSlug, 'url' => '/' . $otherSlug];
        }

        return $this->render('legal/view.html.twig', '/' . $slug, [
            'document' => $documents[$slug] + ['slug' => $slug],
            'more' => $more,
            'breadcrumbs' => [],
        ]);
    }

    private function footer(): string
    {
        return $this->render('partials/footer.html.twig', '/', []);
    }

    /**
     * The whole page, with the three figures the footer needs -- they normally
     * arrive through renderPage()'s context merge, which render() does not do.
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
}
