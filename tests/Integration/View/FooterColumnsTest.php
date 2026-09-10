<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\View;

use DOMDocument;
use DOMElement;
use DOMXPath;
use TripBuilder\Config;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Routes;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\View\LayoutData;
use TripBuilder\View\TwigRenderer;

/**
 * The shape of the footer's link grid.
 *
 * An integration test and not a unit one, which is the whole reason this was
 * missing. Five of the six columns are drawn from the database, and a column
 * with no rows takes itself off the page -- so without a database the footer
 * renders one column and there is nothing to compare. The suite that renders
 * the footer fastest is exactly the suite that cannot see this.
 *
 * Which cuts both ways, and cost this test a round of CI: a column can be
 * absent because something broke, or because the signal behind it has honestly
 * never been written to. `search` is empty on a freshly installed database --
 * searches are recorded by use, not by seeding -- so the routes column is
 * missing there by design. Asserting all six are present was asserting that
 * somebody had used the site.
 */
final class FooterColumnsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
    }

    /**
     * The columns are all the same length as each other.
     *
     * Evening them up was the point of the work that built this band, and the
     * grid gives no clue when one drifts: a short column just leaves white
     * space beneath it that reads as breathing room.
     *
     * This does not claim it would have caught the commit that made them
     * uneven, because it would not -- before that commit the counted columns
     * ran to seven rows and Help & tips to six, so this test would have been
     * failing then and passing after. It pins the state that is wanted from
     * here: level, at whatever length.
     *
     * Length rather than a number, so moving all of them together stays a
     * cheap change and moving one alone does not.
     *
     * On an installed database with no searches recorded, the Directions
     * column has nothing to show and correctly takes itself off the page, so
     * this compares the five that draw. That is not a gap to close here --
     * FooterRenderTest::testADataDrivenColumnFollowsItsData is what checks a
     * column's presence against its own source.
     */
    public function testTheLinkColumnsAreAllTheSameLength(): void
    {
        $lengths = $this->columnLengths();

        self::assertGreaterThan(1, count($lengths), 'there should be a grid of columns to compare');
        self::assertCount(
            1,
            array_unique($lengths),
            'these columns are different lengths: ' . (string) json_encode($lengths),
        );
    }

    /**
     * Every `/help` link in the footer names an article that exists.
     *
     * Moved here from the unit suite when articles became rows. It has to have
     * a database now, but it also does more than it could before: the check
     * used to be `Routes::resolve($href) !== null`, and the help route is a
     * loose pattern, so `/help/anything` satisfied it. A row either exists or
     * it does not.
     */
    public function testEveryHelpLinkNamesAnArticleThatExists(): void
    {
        $html = $this->footer();
        $known = new ArticleRepository($this->connection())->all();

        self::assertNotEmpty($known, 'there should be articles to link');

        preg_match_all('#href="(/help[^"]*)"#', $html, $links);

        self::assertNotEmpty($links[1], 'the help column should have links');

        foreach (array_unique($links[1]) as $href) {
            if ($href === '/help') {
                continue;
            }

            self::assertArrayHasKey(
                substr($href, strlen('/help/')),
                $known,
                $href . ' is linked in the footer but names no article',
            );
        }
    }

    /**
     * The column uses each article's short name where it has one.
     *
     * Some titles are wider than this column: "Refunds and exchanges" measured
     * 170px against the 166 it gets, and "Changing passenger details" is two
     * lines. Those labels live in a nullable column now, and this is what says
     * the measurement survived.
     *
     * Asked of the articles this column actually draws, not of every article
     * there is. It shows a fixed few of however many exist, so one carrying a
     * short name that did not make the cut is missing from this markup for a
     * reason that has nothing to do with its label -- and looking for it there
     * would fail on the catalogue growing rather than on anything breaking.
     */
    public function testTheHelpColumnUsesTheShortNamesAndNotTheTitles(): void
    {
        $html = $this->footerText();
        $articles = new ArticleRepository($this->connection())->all();
        $shortened = 0;

        foreach ($this->helpSlugsInFooter() as $slug) {
            $article = $articles[$slug] ?? null;

            if ($article === null || $article['short'] === null) {
                continue;
            }

            $shortened++;

            self::assertStringContainsString(
                $article['short'],
                $html,
                'the short name should be the label',
            );
            self::assertStringNotContainsString(
                $article['title'],
                $html,
                $article['title'] . ' is too wide for this column and should not appear in it',
            );
        }

        self::assertGreaterThan(
            0,
            $shortened,
            'sanity: at least one article in the column should carry a short name',
        );
    }

    /**
     * The article slugs the footer actually links, in the order it links them.
     *
     * Read off the markup rather than recomputed, so this follows the column
     * however it is ordered and however many it is told to show.
     *
     * @return list<string>
     */
    private function helpSlugsInFooter(): array
    {
        preg_match_all('#href="/help/([a-z-]+)"#', $this->footer(), $matches);

        self::assertNotEmpty($matches[1], 'the footer should link some articles');

        return array_values(array_unique($matches[1]));
    }

    /**
     * A "more" link appears under a column that drew, and nowhere else.
     *
     * Also moved from the unit suite, and for a reason worth recording: every
     * one of the six columns is drawn from the database now, so with no
     * connection none of them draws and this had nothing to count. Help & tips
     * was the last one held in config.
     */
    public function testMoreLinksAppearOnlyWhereConfigured(): void
    {
        $html = $this->footerText();
        $drawn = 0;

        foreach (Config::get('site.footer-columns') as $column) {
            if (!isset($column['more'])) {
                continue;
            }

            // The column is on the page only if it had links to show.
            if (!str_contains($html, '>' . $column['title'] . '</h2>')) {
                continue;
            }

            $drawn++;
            // The configured text is a format string -- "All %s airlines" --
            // so what the page shows is what footerMore() makes of it.
            $text = new LayoutData()->footerMore($column['more'])['text'];
            self::assertStringContainsString('>' . $text . '</a>', $html);
        }

        self::assertGreaterThan(0, $drawn, 'at least one more-link should be drawn');
        self::assertSame(
            $drawn,
            substr_count($html, 'footer__more'),
            'every more-link drawn belongs to a column that was drawn',
        );
    }

    /**
     * The footer, rendered as a fragment with the three figures it prints.
     *
     * `connection()` is touched first so a machine with no database skips
     * these rather than failing them -- the render reaches for its own.
     *
     * Raw, entities and all, because columnLengths() hands this to
     * DOMDocument and that does its own decoding. A test matching strings
     * wants footerText() instead.
     */
    private function footer(): string
    {
        $this->connection();

        Routes::setCurrentPage('/');

        return new TwigRenderer()->render('partials/footer.html.twig', [
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);
    }

    /**
     * The same footer with entities decoded, for matching strings against.
     *
     * Two of the things asserted below carry an ampersand -- the heading
     * "Help & tips" and the label "Refunds & exchanges" -- and Twig escapes
     * both, so a test looking for them raw finds nothing. FooterRenderTest
     * decodes for exactly this reason and says so: spelling `&amp;` in the
     * assertion would be matching Twig's escaping rather than the words.
     *
     * These two tests moved here from that class and this is what came with
     * them; without it they fail on the ampersand and nothing else.
     */
    private function footerText(): string
    {
        return html_entity_decode($this->footer(), ENT_QUOTES | ENT_HTML5);
    }

    /**
     * Rows per column, keyed by heading.
     *
     * @return array<string, int>
     */
    private function columnLengths(): array
    {
        $html = $this->footer();

        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');

        // Direct children only. The Navigation and Repository columns below the
        // grid draw through the same partial and carry the same class, but they
        // are not part of it and are not meant to match its length.
        $columns = new DOMXPath($document)->query(
            '//*[contains(concat(" ", @class, " "), " footer__columns ")]'
            . '/*[contains(concat(" ", @class, " "), " footer__column ")]',
        );

        self::assertNotFalse($columns);

        $lengths = [];

        foreach ($columns as $column) {
            if (!$column instanceof DOMElement) {
                continue;
            }

            $heading = $column->getElementsByTagName('h2')->item(0);
            $lengths[trim($heading->textContent ?? '?')] = $column->getElementsByTagName('li')->length;
        }

        return $lengths;
    }
}
