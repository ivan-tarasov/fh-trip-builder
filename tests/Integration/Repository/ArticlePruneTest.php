<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\ArticleCategoryRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Repository\ArticleVoteRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * Removing an article, a category, and the votes that hang off one.
 *
 * `articles:import` gained these so the tables match the files it is given:
 * before, deleting an article's file left the row on every database that had
 * already imported it while a fresh install never had it, so the two quietly
 * stopped agreeing and only the fresh one matched the repository.
 *
 * Written against the repositories rather than the command because the command
 * decides *which* rows to remove by reading a fixed directory off disk, and
 * pointing it somewhere else would mean making the content path injectable for
 * no reason but this. What is here is the half that touches rows; the half that
 * chooses them is `array_diff` over the files, and the command refuses outright
 * when it finds none -- which is what stops a bad path emptying the tables.
 */
final class ArticlePruneTest extends IntegrationTestCase
{
    /** A slug no article file will ever carry. */
    private const string SENTINEL = 'zzp-not-an-article';

    /** And a second, to prove a delete takes one row and not the table. */
    private const string SENTINEL_TWO = 'zzp-not-an-article-either';

    private const string SENTINEL_CATEGORY = 'zzp-not-a-category';

    protected function tearDown(): void
    {
        // Nothing was written, so there is nothing to tidy -- and a skip
        // raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach (['article_votes', 'article_translations', 'articles'] as $table) {
            $this->connection()->execute(
                'DELETE FROM ' . $table . ' WHERE slug LIKE ?',
                [self::SENTINEL . '%'],
            );
        }

        foreach (['article_category_translations', 'article_categories'] as $table) {
            $this->connection()->execute(
                'DELETE FROM ' . $table . ' WHERE slug = ?',
                [self::SENTINEL_CATEGORY],
            );
        }
    }

    /**
     * An article goes, and its words go with it.
     *
     * Both tables, because a translation left behind is a row nothing can
     * reach: every read starts from `articles` and joins outwards, so an
     * orphaned translation would be invisible and permanent.
     */
    public function testDeletingAnArticleTakesItsTranslations(): void
    {
        $this->insertArticle(self::SENTINEL);
        $this->insertArticle(self::SENTINEL_TWO);

        new ArticleRepository($this->connection())->delete(self::SENTINEL);

        self::assertSame(0, $this->countIn('articles', self::SENTINEL));
        self::assertSame(0, $this->countIn('article_translations', self::SENTINEL));

        // The other one is untouched, which is the difference between a delete
        // and a purge.
        self::assertSame(1, $this->countIn('articles', self::SENTINEL_TWO));
        self::assertSame(1, $this->countIn('article_translations', self::SENTINEL_TWO));
    }

    /**
     * And the votes cast on it.
     *
     * The reason this is not merely tidy: `article_votes` is keyed on the slug,
     * so a later article reusing a retired one would inherit a tally cast
     * about a page nobody can read -- and the footer column ranks on that
     * tally, so it would arrive pre-sorted by opinions of something else.
     */
    public function testDeletingAnArticlesVotesLeavesEveryoneElsesAlone(): void
    {
        $votes = new ArticleVoteRepository($this->connection());

        $votes->record(self::SENTINEL, str_repeat('a', 32), true);
        $votes->record(self::SENTINEL, str_repeat('b', 32), false);
        $votes->record(self::SENTINEL_TWO, str_repeat('c', 32), true);

        self::assertSame(2, $this->countIn('article_votes', self::SENTINEL));

        $votes->delete(self::SENTINEL);

        self::assertSame(0, $this->countIn('article_votes', self::SENTINEL));
        self::assertSame(1, $this->countIn('article_votes', self::SENTINEL_TWO));
    }

    /**
     * A category goes the same way, words and all.
     */
    public function testDeletingACategoryTakesItsTranslations(): void
    {
        $this->insertCategory();

        self::assertContains(self::SENTINEL_CATEGORY, new ArticleCategoryRepository($this->connection())->slugs());

        new ArticleCategoryRepository($this->connection())->delete(self::SENTINEL_CATEGORY);

        self::assertSame(0, $this->countIn('article_categories', self::SENTINEL_CATEGORY));
        self::assertSame(0, $this->countIn('article_category_translations', self::SENTINEL_CATEGORY));
    }

    /**
     * `slugs()` counts an article that is held back.
     *
     * The one read on this repository that ignores `enabled`, and the prune is
     * why. An article disabled months ago is still a row whose file may have
     * gone, and a filtered list would leave exactly those rows behind for
     * good -- unreachable, unlisted, and never tidied.
     */
    public function testSlugsIncludesAnArticleThatIsDisabled(): void
    {
        $this->insertArticle(self::SENTINEL);
        $this->connection()->execute('UPDATE articles SET enabled = 0 WHERE slug = ?', [self::SENTINEL]);

        $repository = new ArticleRepository($this->connection());

        self::assertArrayNotHasKey(self::SENTINEL, $repository->all(), 'a disabled article is not on offer');
        self::assertContains(self::SENTINEL, $repository->slugs(), 'but it is still a row to be pruned');
    }

    private function insertArticle(string $slug): void
    {
        $category = (string) array_key_first(new ArticleCategoryRepository($this->connection())->all());

        self::assertNotSame('', $category, 'there should be a category to file this under');

        $this->connection()->execute(
            'INSERT INTO articles (slug, category, icon, position, enabled, created_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW())',
            [$slug, $category, 'fa-clock', 900],
        );
        $this->connection()->execute(
            'INSERT INTO article_translations (slug, locale, title, short, summary, body, updated_at)'
            . ' VALUES (?, ?, ?, NULL, ?, ?, NOW())',
            [
                $slug,
                ArticleRepository::DEFAULT_LOCALE,
                'Not an article',
                'A row the test suite inserts and removes again.',
                '## Heading',
            ],
        );
    }

    private function insertCategory(): void
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
                'Not a category',
                'A row the test suite inserts and removes again.',
            ],
        );
    }

    private function countIn(string $table, string $slug): int
    {
        return (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE slug = ?',
            [$slug],
        );
    }
}
