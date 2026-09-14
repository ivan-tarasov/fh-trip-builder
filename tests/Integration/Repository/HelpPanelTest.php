<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\ArticleCategoryRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * What the admin panel needs that the site does not.
 *
 * Every other read of these tables filters `enabled = 1`, which is what makes
 * holding an article back actually hold it back -- it leaves the hub, the
 * aside, the sitemap and the footer in one go. The panel is the one reader
 * that has to see what it is hiding, which is why `forPanel()` and
 * `forEditing()` exist rather than a flag on the existing reads (A3.3, #101).
 *
 * Slugs here are prefixed so they cannot collide with the real content, and
 * they are removed afterwards: these tables hold the site's own help pages.
 */
final class HelpPanelTest extends IntegrationTestCase
{
    private const string CATEGORY = 'zz-test-category';
    private const array ARTICLES = ['zz-test-one', 'zz-test-two', 'zz-test-three'];

    protected function setUp(): void
    {
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->categories()->store(
            self::CATEGORY,
            ['icon' => 'fa-flask', 'accent' => 'blue', 'position' => 900],
            ['title' => 'A test category', 'summary' => 'Written by a test.'],
        );

        foreach (self::ARTICLES as $at => $slug) {
            $this->articles()->store(
                $slug,
                ['category' => self::CATEGORY, 'icon' => 'fa-flask', 'position' => ($at + 1) * 10],
                ['title' => 'Test ' . $slug, 'short' => null, 'summary' => 'A summary.', 'body' => '## Body'],
            );
        }
    }

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach (self::ARTICLES as $slug) {
            $this->articles()->delete($slug);
        }

        $this->categories()->delete(self::CATEGORY);
    }

    public function testTheListShowsWhatTheSiteIsHiding(): void
    {
        $this->articles()->setEnabled(self::ARTICLES[1], false);

        $listed = $this->panelArticles();

        self::assertArrayHasKey(self::ARTICLES[1], $listed, 'a hidden article vanished from the panel too');
        self::assertFalse($listed[self::ARTICLES[1]]['enabled']);
        self::assertTrue($listed[self::ARTICLES[0]]['enabled']);

        // And the site's own read still hides it, which is the point of the flag.
        self::assertArrayNotHasKey(self::ARTICLES[1], $this->articles()->all());
    }

    public function testAHiddenArticleCanStillBeOpened(): void
    {
        $this->articles()->setEnabled(self::ARTICLES[0], false);

        // `find()` is the site's read and refuses, as it should.
        self::assertNull($this->articles()->find(self::ARTICLES[0]));

        $editing = $this->articles()->forEditing(self::ARTICLES[0]);

        self::assertNotNull($editing, 'a hidden article could not be edited, so it could never be shown again');
        self::assertFalse($editing['enabled']);
        self::assertSame('## Body', $editing['body']);
    }

    public function testHidingAndShowing(): void
    {
        $this->articles()->setEnabled(self::ARTICLES[0], false);
        self::assertFalse((bool) $this->panelArticles()[self::ARTICLES[0]]['enabled']);

        $this->articles()->setEnabled(self::ARTICLES[0], true);
        self::assertTrue((bool) $this->panelArticles()[self::ARTICLES[0]]['enabled']);
    }

    /**
     * A move is a swap with the neighbour, not a step.
     *
     * The seeded positions are 10, 20, 30, so adding one to a position moved
     * nothing at all and the button looked broken. Caught in the browser, and
     * this is what stops it coming back.
     */
    public function testMovingAnArticleActuallyMovesIt(): void
    {
        self::assertSame(self::ARTICLES, $this->orderInCategory());

        $this->articles()->move(self::ARTICLES[2], -1);

        self::assertSame(
            [self::ARTICLES[0], self::ARTICLES[2], self::ARTICLES[1]],
            $this->orderInCategory(),
        );

        $this->articles()->move(self::ARTICLES[2], 1);

        self::assertSame(self::ARTICLES, $this->orderInCategory());
    }

    public function testMovingPastTheEndDoesNothing(): void
    {
        $this->articles()->move(self::ARTICLES[0], -1);
        self::assertSame(self::ARTICLES, $this->orderInCategory());

        $this->articles()->move(self::ARTICLES[2], 1);
        self::assertSame(self::ARTICLES, $this->orderInCategory());
    }

    public function testMovingSomethingThatIsNotThereDoesNothing(): void
    {
        $this->articles()->move('zz-no-such-article', -1);

        self::assertSame(self::ARTICLES, $this->orderInCategory());
    }

    /**
     * A category is the same operation on another table.
     */
    public function testACategoryCanBeHiddenAndStillEdited(): void
    {
        $this->categories()->setEnabled(self::CATEGORY, false);

        self::assertArrayNotHasKey(self::CATEGORY, $this->categories()->all());

        $editing = $this->categories()->forEditing(self::CATEGORY);

        self::assertNotNull($editing);
        self::assertFalse($editing['enabled']);
        self::assertSame('A test category', $editing['title']);
    }

    /**
     * Hiding a category keeps its articles, which is why `slugs()` is
     * unfiltered: the importer checks membership against it, and refusing
     * would mean a category could not be hidden without breaking the next
     * import of everything in it.
     */
    public function testAHiddenCategoryStillOwnsItsArticles(): void
    {
        $this->categories()->setEnabled(self::CATEGORY, false);

        self::assertContains(self::CATEGORY, $this->categories()->slugs());
        self::assertArrayHasKey(self::ARTICLES[0], $this->panelArticles());
    }

    public function testAnArticleThatIsNotThereReadsAsNothing(): void
    {
        self::assertNull($this->articles()->forEditing('zz-no-such-article'));
        self::assertNull($this->categories()->forEditing('zz-no-such-category'));
    }

    /** @return array<string, array<string, mixed>> */
    private function panelArticles(): array
    {
        $listed = [];

        foreach ($this->articles()->forPanel() as $article) {
            $listed[$article['slug']] = $article;
        }

        return $listed;
    }

    /** @return list<string> */
    private function orderInCategory(): array
    {
        $order = [];

        foreach ($this->articles()->forPanel() as $article) {
            if ($article['category'] === self::CATEGORY) {
                $order[] = $article['slug'];
            }
        }

        return $order;
    }

    private function articles(): ArticleRepository
    {
        return new ArticleRepository($this->connection());
    }

    private function categories(): ArticleCategoryRepository
    {
        return new ArticleCategoryRepository($this->connection());
    }
}
