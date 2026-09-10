<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\View;

use Throwable;
use TripBuilder\Config;
use TripBuilder\Controllers\HelpController;
use TripBuilder\Http\Input;
use TripBuilder\Http\Request;
use TripBuilder\Party;
use TripBuilder\Repository\ArticleCategoryRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Routes;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\Tests\Unit\View\PromisesTest;
use TripBuilder\Timer;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\Markdown;
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
 * The prose is a row too now, so an article is one thing in one place and the
 * two-catalogues problem is gone. What replaced it is that `articles:import`
 * is run by hand: a markdown file added without running it is an article that
 * exists in the repository and nowhere else, which is what
 * testEveryArticleFileHasBeenImported notices.
 */
final class ArticleCatalogueTest extends IntegrationTestCase
{
    /** The article testTheDateShownIsTheDateStored inserts, cleared in tearDown(). */
    private const string SENTINEL = 'zzz-date-sentinel';

    /** A date no import could have written, so the clock cannot be mistaken for it. */
    private const string SENTINEL_DATE = '2019-03-04 09:12:00';

    /** An empty category, for the one test that needs a group with nothing in it. */
    private const string SENTINEL_CATEGORY = 'zzz-category-sentinel';

    /**
     * A second throwaway article, for the one case that needs two.
     *
     * An orphan on its own has no siblings and is answered by that, so it
     * never reaches the check for whether its category exists. Two orphans
     * sharing a missing category do.
     */
    private const string SENTINEL_TWO = 'zzz-date-sentinel-two';

    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
    }

    /**
     * Removed after every test, the way TopRatedHelpTest clears its voters:
     * one test inserts an article, and an article left behind would be a sixth
     * row that every other test here counts.
     */
    protected function tearDown(): void
    {
        // Nothing was written, so there is nothing to tidy -- and a skip
        // raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach (['article_translations', 'articles'] as $table) {
            foreach ([self::SENTINEL, self::SENTINEL_TWO] as $slug) {
                $this->connection()->execute('DELETE FROM ' . $table . ' WHERE slug = ?', [$slug]);
            }
        }

        foreach (['article_category_translations', 'article_categories'] as $table) {
            $this->connection()->execute(
                'DELETE FROM ' . $table . ' WHERE slug = ?',
                [self::SENTINEL_CATEGORY],
            );
        }
    }

    /**
     * Every article on offer has prose stored against it.
     *
     * This used to check for a template per slug, when the prose was a Twig
     * file and an article was two things in two places. It is one thing now,
     * and what can go wrong instead is a row imported with an empty body --
     * which renders as a heading and nothing else.
     */
    public function testEveryArticleHasProseStored(): void
    {
        $empty = [];

        foreach (array_keys($this->articles()) as $slug) {
            $article = new ArticleRepository($this->connection())->find($slug);

            if ($article === null || trim($article['body']) === '') {
                $empty[] = $slug;
            }
        }

        self::assertSame([], $empty, 'these articles are offered with no prose behind them');
    }

    /**
     * And every committed file has a row, so nothing is written and forgotten.
     *
     * `articles:import` is the only thing that writes these, and it is run by
     * hand -- so a file added without running it is an article that exists in
     * the repository and nowhere else. This is what notices.
     */
    public function testEveryArticleFileHasBeenImported(): void
    {
        $slugs = array_keys($this->articles());
        $missing = [];

        foreach (glob(dirname(__DIR__, 3) . '/config/content/help/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');

            if (!in_array($slug, $slugs, true)) {
                $missing[] = $slug;
            }
        }

        self::assertSame([], $missing, 'these files have never been imported (run articles:import)');
    }

    /**
     * No stored article promises mail this app cannot send.
     *
     * The counterpart to PromisesTest, and the half that matters most now.
     * That suite scans the committed markdown, which is what review sees; this
     * scans what is actually on the table, which is what a reader gets. The
     * article the whole guard exists for -- /help/ticket-not-received -- is a
     * row now, and a row can be edited without any file changing.
     *
     * The phrase list is PromisesTest's, referenced rather than copied.
     */
    public function testNoStoredArticlePromisesMailNothingCanSend(): void
    {
        $offences = [];

        foreach ($this->articles() as $slug => $article) {
            $prose = mb_strtolower(new ArticleRepository($this->connection())->find($slug)['body'] ?? '');

            foreach (PromisesTest::UNKEEPABLE as $phrase) {
                if (str_contains($prose, $phrase)) {
                    $offences[] = $slug . ' says "' . $phrase . '"';
                }
            }
        }

        self::assertSame([], $offences);
    }

    /**
     * And no stored body quotes a figure Party owns.
     *
     * The regression the rewording was chosen to avoid, and the reason it was
     * a rewording rather than a placeholder. The two templates these bodies
     * replaced printed the shares live -- `{{ (shares.child_fare * 100) }}%`
     * and `{{ max_seats }} seats at most` -- so they could not go stale. Prose
     * in a row can: a body typing 75% keeps saying 75% after somebody changes
     * CHILD_FARE, on the page a reader goes to in order to check.
     *
     * So the figures are derived here rather than listed. Change a share and
     * this starts looking for the new number, which is the whole point; the
     * bodies state the rule instead and have nothing to go stale.
     */
    public function testNoStoredArticleQuotesAFigurePartyOwns(): void
    {
        $forbidden = [];

        // The fares only, not the tax shares. Those are 100% and 0%, and a
        // refunds article is entitled to say "100% refundable" about something
        // else entirely -- a guard that cries wolf on a correct sentence is a
        // guard somebody deletes. 75% and 10% are the figures the templates
        // printed and the ones worth spelling out here.
        foreach (Party::shares() as $name => $share) {
            if (!str_ends_with($name, '_fare')) {
                continue;
            }

            $forbidden[$name] = '/\b' . (int) round($share * 100) . '\s*(%|per ?cent)/i';
        }

        self::assertNotEmpty($forbidden, 'the share keys have been renamed -- this guard now checks nothing');

        // Spelled out as well as printed, because "nine seats" is how prose
        // would say it. Asserted to cover the limit rather than assumed: a
        // seat limit outside this map would leave the pattern below looking
        // for a number no article could contain, and the guard would pass by
        // guarding nothing.
        $words = [8 => 'eight', 9 => 'nine', 10 => 'ten'];
        self::assertArrayHasKey(
            Party::MAX_SEATS,
            $words,
            'the seat limit has moved -- add its word here or this guard stops guarding',
        );

        $forbidden['max_seats'] = '/\b(' . Party::MAX_SEATS . '|' . $words[Party::MAX_SEATS]
            . ')\b\D{0,24}(seat|passenger|traveller)/i';

        $offences = [];

        foreach ($this->articles() as $slug => $article) {
            $body = (string) (new ArticleRepository($this->connection())->find($slug)['body'] ?? '');

            foreach ($forbidden as $name => $pattern) {
                if (preg_match($pattern, $body) === 1) {
                    $offences[] = $slug . ' quotes ' . $name;
                }
            }
        }

        self::assertSame([], $offences);
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


    /**
     * The hub draws every category, with its own articles under it.
     *
     * Driven through the controller because the grouping is the controller's
     * -- the two repositories deliberately do not join, so a test that built
     * the groups itself would be checking its own arithmetic.
     */
    public function testTheHubDrawsEveryCategoryAndItsArticles(): void
    {
        $html = $this->hub();
        $categories = new ArticleCategoryRepository($this->connection())->all();
        $articles = new ArticleRepository($this->connection())->all();

        self::assertNotEmpty($categories, 'no categories to draw');

        foreach ($categories as $slug => $category) {
            self::assertStringContainsString('href="#' . $slug . '"', $html, $slug . ' has no rail link');
            self::assertStringContainsString('id="' . $slug . '"', $html, $slug . ' has no card');
            self::assertStringContainsString(
                htmlspecialchars($category['title'], ENT_QUOTES),
                $html,
                $slug . ' is not named',
            );
        }

        foreach ($articles as $slug => $article) {
            self::assertStringContainsString(
                'href="/help/' . $slug . '"',
                $html,
                $slug . ' is not linked from the hub',
            );
        }
    }

    /**
     * An orphaned article is left off the hub, and stays everywhere else.
     *
     * The other half of the LEFT JOIN decision. `all()` keeps a row whose
     * category names nothing so the footer, the sitemap and every aside still
     * carry it; the hub is a page of groups and has none to draw it in, so it
     * is the one reader that drops it. What must not happen is the reverse of
     * either: a page vanishing from the site, or a card with no heading.
     */
    public function testAnOrphanedArticleIsLeftOffTheHubButStaysOnTheSite(): void
    {
        $this->insertSentinel('no-such-category-exists');

        self::assertArrayHasKey(
            self::SENTINEL,
            new ArticleRepository($this->connection())->all(),
            'the orphan should still be part of the site',
        );

        $html = $this->hub();

        self::assertStringNotContainsString('/help/' . self::SENTINEL, $html, 'the hub should not draw it');
        self::assertStringNotContainsString(
            'no-such-category-exists',
            $html,
            'and it should certainly not invent a group for it',
        );
    }

    /**
     * A category with nothing in it is not drawn.
     *
     * It would otherwise be a heading, a sentence and a rule with no rows
     * under it, plus a rail link that scrolls to it -- which reads as an
     * article list that failed to load rather than as a group nobody has
     * written for yet. Held back deliberately, since the admin panel will let
     * a category exist before its first article does.
     */
    public function testACategoryWithNoArticlesIsNotDrawn(): void
    {
        $this->connection()->execute(
            'INSERT INTO article_categories (slug, icon, accent, position, enabled, created_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW())',
            [self::SENTINEL_CATEGORY, 'fa-clock', 'violet', 990],
        );
        $this->connection()->execute(
            'INSERT INTO article_category_translations (slug, locale, title, summary, updated_at)'
            . ' VALUES (?, ?, ?, ?, NOW())',
            [
                self::SENTINEL_CATEGORY,
                ArticleCategoryRepository::DEFAULT_LOCALE,
                'A group with nothing in it',
                'Inserted by the test suite and removed again.',
            ],
        );

        // The repository still offers it -- it is a real, enabled category.
        self::assertArrayHasKey(
            self::SENTINEL_CATEGORY,
            new ArticleCategoryRepository($this->connection())->all(),
        );

        $html = $this->hub();

        self::assertStringNotContainsString(self::SENTINEL_CATEGORY, $html, 'no card and no rail link');
        self::assertStringNotContainsString('A group with nothing in it', $html);
    }

    /**
     * Articles come back grouped by category, in category order.
     *
     * The contract `all()` gained with categories, and the one every caller
     * quietly leans on: the hub walks this list once and starts a new card
     * whenever the category changes, so a list that returned two runs of the
     * same category would draw that category twice. The footer column and the
     * sitemap take their order from here too.
     */
    public function testArticlesComeBackGroupedByCategoryInOrder(): void
    {
        $categories = array_keys(new ArticleCategoryRepository($this->connection())->all());

        $runs = [];

        foreach (new ArticleRepository($this->connection())->all() as $article) {
            if ($runs === [] || end($runs) !== $article['category']) {
                $runs[] = (string) $article['category'];
            }
        }

        self::assertNotEmpty($runs, 'no articles to group');

        // Compared against the categories that actually have articles, so a
        // category written with none in it yet does not fail this.
        self::assertSame(
            array_values(array_intersect($categories, $runs)),
            $runs,
            'a category appears more than once, or out of order',
        );
    }

    /**
     * An article whose category is missing is kept, and sorted last.
     *
     * The deliberate consequence of the LEFT JOIN in `all()`. There is no
     * foreign key on `category` -- this schema has none anywhere -- so a row
     * can point at nothing, and an inner join would answer that by dropping
     * the article out of the footer, the sitemap and every aside at once.
     * Showing it in an odd place is the better failure, and "last" rather than
     * "first" is the whole reason the ordering leads with `IS NULL`: MySQL
     * sorts NULL first ascending, which would have put an orphan at the top of
     * the footer column.
     */
    public function testAnArticleWithNoSuchCategoryIsKeptAndSortsLast(): void
    {
        $this->insertSentinel('no-such-category-exists');

        $all = new ArticleRepository($this->connection())->all();

        self::assertArrayHasKey(self::SENTINEL, $all, 'an orphan should not vanish from the site');
        self::assertSame(self::SENTINEL, array_key_last($all), 'an orphan should sort last, not first');
    }

    /**
     * Every article page says when it last changed.
     *
     * The plainest half of it: the line is only on the page while the
     * controller passes the date, and the template hides it silently when
     * nothing arrives -- so nothing else here would notice it going missing.
     */
    public function testEveryArticlePageSaysWhenItLastChanged(): void
    {
        foreach (array_keys($this->articles()) as $slug) {
            self::assertStringContainsString(
                'Last updated',
                $this->page($slug),
                $slug . ' does not say when it last changed',
            );
        }
    }

    /**
     * And the date shown is the date stored, not the date today.
     *
     * Worth inserting an article for. Every real one was imported on the same
     * afternoon, so a page printing today rather than the row would agree with
     * all five and this would pass while proving nothing. A row dated 2019
     * cannot be confused with the clock.
     *
     * The day is compared rather than the string, so how a date is written
     * stays the template's business and only the fact is asserted here.
     */
    public function testTheDateShownIsTheDateStored(): void
    {
        $this->insertSentinel();

        preg_match('/Last updated ([^.<]+)\./', $this->page(self::SENTINEL), $shown);

        self::assertNotEmpty($shown, 'the page should say when the article changed');

        $day = date('Y-m-d', (int) strtotime($shown[1]));

        self::assertSame(date('Y-m-d', (int) strtotime(self::SENTINEL_DATE)), $day);
        self::assertNotSame(date('Y-m-d'), $day, 'the page is printing today instead of the stored date');
    }
    /**
     * Each article offers the rest of its own group, and nothing else.
     *
     * Driven through the controller, because that is where the grouping is
     * decided and because the template hides the card when nothing arrives --
     * `siblings|default(false)`, which is what keeps the band suites from
     * having to know about this. Nothing else here would notice the controller
     * stopping.
     */
    public function testAnArticleOffersTheRestOfItsGroup(): void
    {
        $articles = new ArticleRepository($this->connection())->all();
        $categories = new ArticleCategoryRepository($this->connection())->all();

        $checked = 0;

        foreach ($articles as $slug => $article) {
            $expected = array_keys(array_filter(
                $articles,
                static fn(array $other, string $key): bool
                    => $key !== $slug && $other['category'] === $article['category'],
                ARRAY_FILTER_USE_BOTH,
            ));

            $html = $this->articlePage($slug);

            preg_match('#<aside class="article__aside.*?</aside>#s', $html, $aside);

            if ($expected === []) {
                self::assertEmpty($aside, $slug . ' is alone in its group and should offer no card');

                continue;
            }

            self::assertNotEmpty($aside, $slug . ' should offer its group');
            self::assertStringContainsString(
                htmlspecialchars($categories[$article['category']]['title'], ENT_QUOTES),
                $aside[0],
                $slug . ' should name its group',
            );
            self::assertStringContainsString(
                'fas ' . $categories[$article['category']]['icon'],
                $aside[0],
                $slug . ' should carry its group\'s icon',
            );

            preg_match_all('#href="/help/([a-z-]+)"#', $aside[0], $links);

            self::assertSame($expected, $links[1], $slug . ' offers the wrong articles');
            self::assertNotContains($slug, $links[1], $slug . ' links back to itself');

            $checked++;
        }

        self::assertGreaterThan(0, $checked, 'no article had a sibling to check');
    }

    /**
     * An article with nowhere to point offers no card, either way round.
     *
     * Two ways to have no siblings, and the real catalogue has neither: every
     * group holds two or more, so the branch in the test above never runs on
     * live rows. Both are reachable the moment somebody writes a group's first
     * article, or edits a category out from under one, so both are made to
     * happen here rather than waited for.
     */
    public function testAnArticleWithNoSiblingsOffersNoCard(): void
    {
        // Alone in a group of its own.
        $this->connection()->execute(
            'INSERT INTO article_categories (slug, icon, accent, position, enabled, created_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW())',
            [self::SENTINEL_CATEGORY, 'fa-clock', 'violet', 990],
        );
        $this->connection()->execute(
            'INSERT INTO article_category_translations (slug, locale, title, summary, updated_at)'
            . ' VALUES (?, ?, ?, ?, NOW())',
            [
                self::SENTINEL_CATEGORY,
                ArticleCategoryRepository::DEFAULT_LOCALE,
                'A group of one',
                'Inserted by the test suite and removed again.',
            ],
        );
        $this->insertSentinel(self::SENTINEL_CATEGORY);

        self::assertStringNotContainsString(
            'article__aside',
            $this->articlePage(self::SENTINEL),
            'an article alone in its group should offer no card',
        );

        // And with its category gone from under it. Two of them sharing the
        // same missing category, so this really does reach the check for
        // whether the category exists: a single orphan has no siblings and is
        // answered by that first, which is the weaker thing to assert.
        foreach (['article_translations', 'articles'] as $table) {
            $this->connection()->execute('DELETE FROM ' . $table . ' WHERE slug = ?', [self::SENTINEL]);
        }

        $this->insertSentinel('no-such-category-exists');
        $this->insertSentinel('no-such-category-exists', self::SENTINEL_TWO);

        $html = $this->articlePage(self::SENTINEL);

        self::assertStringNotContainsString(
            'article__aside',
            $html,
            'an orphan should offer no card rather than one with no heading',
        );
        self::assertStringNotContainsString(
            'no-such-category-exists',
            $html,
            'and should not print the missing category as a title',
        );
    }

    /**
     * And the controller is what actually puts the date there.
     *
     * page() below builds a page the way HelpController builds one, which
     * means it supplies the date itself -- so every other assertion in this
     * file would still pass with the controller no longer passing it at all.
     * This drives the controller instead, the way NotFoundAnswerTest drives
     * the search one, and it is the only thing here that would notice.
     */
    public function testTheControllerPutsTheDateOnThePage(): void
    {
        $slug = (string) array_key_first($this->articles());

        // Started because renderPage() reports how long the page took and
        // Timer refuses to be read before it runs -- index.php starts it, so a
        // test driving a controller has to as well. NotFoundAnswerTest does
        // the same thing for the same reason.
        Timer::start();

        $controller = new HelpController(
            new Request(new Input(), new Input(), new Input(), uri: '/help/' . $slug),
        );

        ob_start();

        try {
            $controller->show();
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        self::assertStringNotContainsString('Something went wrong', $html, $slug . ' did not render');
        self::assertStringContainsString('Last updated', $html, $slug . ' arrived with no date on it');
    }

    /**
     * One article page, as HelpController draws it.
     */
    private function articlePage(string $slug): string
    {
        // See hub(): the controller finds its own connection, so this asks
        // for one first to turn a missing database into a skip.
        $this->connection();

        Timer::start();

        $controller = new HelpController(
            new Request(new Input(), new Input(), new Input(), uri: '/help/' . $slug),
        );

        ob_start();

        try {
            $controller->show();
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        self::assertStringNotContainsString('Something went wrong', $html, $slug . ' did not render');

        return $html;
    }

    /**
     * The hub, as HelpController draws it.
     */
    private function hub(): string
    {
        // Asked for and thrown away, so the test skips rather than explodes
        // where there is no database. The controller reaches for its own
        // connection through Connection::fromEnv() and knows nothing about
        // this suite's guard, so without this the page raises a PDOException
        // instead of the skip every other test here gets.
        $this->connection();

        // Started because renderPage() reports how long the page took; see
        // testTheControllerPutsTheDateOnThePage.
        Timer::start();

        $controller = new HelpController(
            new Request(new Input(), new Input(), new Input(), uri: '/help'),
        );

        ob_start();

        try {
            $controller->index();
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        self::assertStringNotContainsString('Something went wrong', $html, 'the hub did not render');

        return $html;
    }

    /**
     * One throwaway article, removed again in tearDown().
     *
     * `category` is NOT NULL with no default, so an insert that skipped it
     * would fail outright. The default is read from the table rather than
     * written out here, so renaming a category does not break this.
     */
    private function insertSentinel(?string $category = null, string $slug = self::SENTINEL): void
    {
        $category ??= (string) array_key_first(new ArticleCategoryRepository($this->connection())->all());

        self::assertNotSame('', $category, 'there should be a category to file the sentinel under');

        $this->connection()->execute(
            'INSERT INTO articles (slug, category, icon, position, enabled, created_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW())',
            [$slug, $category, 'fa-clock', 900],
        );
        $this->connection()->execute(
            'INSERT INTO article_translations (slug, locale, title, short, summary, body, updated_at)'
            . ' VALUES (?, ?, ?, NULL, ?, ?, ?)',
            [
                $slug,
                ArticleRepository::DEFAULT_LOCALE,
                'When this changed',
                'A row the test suite inserts and removes again.',
                "## Heading\n\nOne paragraph.",
                self::SENTINEL_DATE,
            ],
        );
    }

    /** @return array<string, array{title: string, short: ?string, icon: string, category: string, summary: string}> */
    private function articles(): array
    {
        $articles = new ArticleRepository($this->connection())->all();

        self::assertNotEmpty($articles, 'no articles on the table to test');

        return $articles;
    }

    /**
     * One article page, built the way HelpController builds it.
     *
     * Which means converting the stored markdown here too. The controller
     * hands the template finished HTML rather than prose, because the one
     * thing this app never does is treat stored text as template source -- so
     * a test that passed the body straight in would be rendering a page the
     * app cannot produce.
     */
    private function page(string $slug): string
    {
        $articles = $this->articles();
        $stored = new ArticleRepository($this->connection())->find($slug);

        self::assertNotNull($stored, $slug . ' is on offer with no prose stored');

        $more = [];

        foreach (array_diff_key($articles, [$slug => null]) as $other => $article) {
            $more[] = $article + ['slug' => $other, 'url' => '/help/' . $other];
        }

        Routes::setCurrentPage('/help/' . $slug);

        return new TwigRenderer()->render('help/view.html.twig', [
            'article_html' => Markdown::toHtml($stored['body']),
            'updated_at' => $stored['updated_at'],
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
