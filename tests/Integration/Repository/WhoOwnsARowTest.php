<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\ImportOutcome;
use TripBuilder\Repository\ArticleCategoryRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The importer owns a row until a person edits it, and then they do.
 *
 * A3.4 (#102), settled 2026-09-14. Before it, `articles:import` rewrote any row
 * whose words differed from its file and deleted any row no file described --
 * so the panel A3.3 had just built was a place to type things the next deploy
 * quietly undid. Measured at the time: the words were reverted, the order was
 * rewritten, and only the hidden flag survived.
 *
 * Two failures are worth guarding and they are not the same size. Rewriting
 * somebody's paragraph is annoying; deleting an article written in the panel --
 * which has no file, so the prune reads it as one the files dropped -- loses
 * work that exists nowhere else.
 */
final class WhoOwnsARowTest extends IntegrationTestCase
{
    /** Slugs no content file will ever carry. */
    private const string ARTICLE = 'zzo-owned-article';
    private const string CATEGORY = 'zzo-owned-category';

    private const array ARTICLE_ROW = ['category' => self::CATEGORY, 'icon' => 'fa-flask', 'position' => 900];
    private const array CATEGORY_ROW = ['icon' => 'fa-flask', 'accent' => 'blue', 'position' => 900];

    protected function setUp(): void
    {
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->categories()->store(self::CATEGORY, self::CATEGORY_ROW, self::categoryWords('From the file'));
        $this->articles()->store(self::ARTICLE, self::ARTICLE_ROW, self::words('From the file'));
    }

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->articles()->delete(self::ARTICLE);
        $this->categories()->delete(self::CATEGORY);
    }

    /**
     * A row nobody has touched belongs to the files, and behaves as it always
     * did.
     */
    public function testTheFilesOwnARowNobodyHasEdited(): void
    {
        self::assertFalse($this->articles()->isHandEdited(self::ARTICLE));
        self::assertNotContains(self::ARTICLE, $this->articles()->handEditedSlugs());

        self::assertSame(
            ImportOutcome::Unchanged,
            $this->articles()->store(self::ARTICLE, self::ARTICLE_ROW, self::words('From the file')),
        );

        self::assertSame(
            ImportOutcome::Written,
            $this->articles()->store(self::ARTICLE, self::ARTICLE_ROW, self::words('Rewritten from the file')),
        );

        self::assertSame('Rewritten from the file', $this->title());
    }

    /**
     * Saving in the panel takes the row over, and the importer says so.
     */
    public function testEditingTakesTheRow(): void
    {
        $this->articles()->edit(self::ARTICLE, self::ARTICLE_ROW, self::words('Written here'));

        self::assertTrue($this->articles()->isHandEdited(self::ARTICLE));
        self::assertContains(self::ARTICLE, $this->articles()->handEditedSlugs());

        self::assertSame(
            ImportOutcome::Kept,
            $this->articles()->store(self::ARTICLE, self::ARTICLE_ROW, self::words('The file disagrees')),
        );

        // The whole point: the words are still the ones somebody typed.
        self::assertSame('Written here', $this->title());
    }

    /**
     * And the panel can go on editing its own row.
     */
    public function testAnOwnedRowCanStillBeEdited(): void
    {
        $this->articles()->edit(self::ARTICLE, self::ARTICLE_ROW, self::words('First pass'));
        $this->articles()->edit(self::ARTICLE, self::ARTICLE_ROW, self::words('Second pass'));

        self::assertSame('Second pass', $this->title());
        self::assertTrue($this->articles()->isHandEdited(self::ARTICLE));
    }

    /**
     * `--force` gives the rows back, and then the files win again.
     */
    public function testReclaimingGivesTheRowBackToTheFiles(): void
    {
        $this->articles()->edit(self::ARTICLE, self::ARTICLE_ROW, self::words('Written here'));

        self::assertGreaterThanOrEqual(1, $this->articles()->reclaim());
        self::assertFalse($this->articles()->isHandEdited(self::ARTICLE));

        self::assertSame(
            ImportOutcome::Written,
            $this->articles()->store(self::ARTICLE, self::ARTICLE_ROW, self::words('From the file')),
        );

        self::assertSame('From the file', $this->title());
    }

    /**
     * The panel flags it, so somebody can see whose row it is before they
     * wonder why an import did nothing to it.
     */
    public function testThePanelSaysWhoOwnsIt(): void
    {
        self::assertFalse($this->panelRow()['edited']);
        self::assertFalse($this->editing()['edited']);

        $this->articles()->edit(self::ARTICLE, self::ARTICLE_ROW, self::words('Written here'));

        self::assertTrue($this->panelRow()['edited']);
        self::assertTrue($this->editing()['edited']);
    }

    /**
     * A category is the same rule on another table.
     */
    public function testACategoryIsOwnedTheSameWay(): void
    {
        self::assertSame(
            ImportOutcome::Unchanged,
            $this->categories()->store(self::CATEGORY, self::CATEGORY_ROW, self::categoryWords('From the file')),
        );

        $this->categories()->edit(self::CATEGORY, self::CATEGORY_ROW, self::categoryWords('Written here'));

        self::assertTrue($this->categories()->isHandEdited(self::CATEGORY));
        self::assertSame(
            ImportOutcome::Kept,
            $this->categories()->store(self::CATEGORY, self::CATEGORY_ROW, self::categoryWords('The file disagrees')),
        );
        $stored = $this->categories()->forEditing(self::CATEGORY);

        self::assertNotNull($stored);
        self::assertSame('Written here', $stored['title']);
    }

    /** @return array<string, mixed> */
    private function panelRow(): array
    {
        foreach ($this->articles()->forPanel() as $row) {
            if ($row['slug'] === self::ARTICLE) {
                return $row;
            }
        }

        self::fail('the fixture is not in the panel list');
    }

    private function title(): string
    {
        return $this->editing()['title'];
    }

    /**
     * The fixture as the editor would load it.
     *
     * @return array{slug: string, title: string, short: ?string, icon: string, category: string, summary: string, body: string, position: int, enabled: bool, edited: bool}
     */
    private function editing(): array
    {
        $row = $this->articles()->forEditing(self::ARTICLE);

        self::assertNotNull($row, 'the fixture is gone');

        return $row;
    }

    /** @return array{title: string, short: ?string, summary: string, body: string} */
    private static function words(string $title): array
    {
        return ['title' => $title, 'short' => null, 'summary' => 'A summary.', 'body' => '## Body'];
    }

    /** @return array{title: string, summary: string} */
    private static function categoryWords(string $title): array
    {
        return ['title' => $title, 'summary' => 'A summary.'];
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
