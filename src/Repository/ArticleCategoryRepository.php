<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\ImportOutcome;

/**
 * The groups the help hub is built out of.
 *
 * Separate from ArticleRepository rather than folded into it, because the two
 * answer different questions and the hub asks both: "what groups are there,
 * in order" and "what articles are there, in order". Joining them into one
 * read would hand a template rows it has to regroup, and the regrouping is
 * where an off-by-one in a heading comes from.
 *
 * Not memoised, for the reason ArticleRepository is not: the importer writes
 * these rows in the same process that reads them back to check its work, and
 * a cache that cannot see a write is worse than a second query on a page that
 * runs three.
 */
final readonly class ArticleCategoryRepository
{
    /**
     * The one language there is, until there is a second.
     *
     * The same constant ArticleRepository carries, and the same promise: this
     * is the line the language branch replaces, not a value scattered through
     * the reads.
     */
    public const string DEFAULT_LOCALE = 'en';

    /** What a category gets when its file does not choose. */
    public const string DEFAULT_ACCENT = 'blue';

    public function __construct(private Connection $connection) {}

    /**
     * Every category on offer, keyed by slug, lowest `position` first.
     *
     * @return array<string, array{title: string, summary: string, icon: string, accent: string}>
     */
    public function all(string $locale = self::DEFAULT_LOCALE): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT c.slug, c.icon, c.accent, t.title, t.summary'
            . ' FROM ' . Table::ArticleCategories->value . ' c'
            . ' JOIN ' . Table::ArticleCategoryTranslations->value . ' t'
            . '  ON t.slug = c.slug AND t.locale = ?'
            . ' WHERE c.enabled = 1'
            // Slug second so two categories sharing a position still come back
            // in a fixed order rather than whichever the engine offers.
            . ' ORDER BY c.position ASC, c.slug ASC',
            [$locale],
        );

        $categories = [];

        foreach ($rows as $row) {
            $categories[(string) $row['slug']] = [
                'title' => (string) $row['title'],
                'summary' => (string) $row['summary'],
                'icon' => (string) $row['icon'],
                'accent' => (string) $row['accent'],
            ];
        }

        return $categories;
    }

    /**
     * Every category the panel can edit, disabled ones included.
     *
     * @return list<array{slug: string, title: string, position: int, enabled: bool, edited: bool}>
     */
    public function forPanel(string $locale = self::DEFAULT_LOCALE): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT c.slug, c.position, c.enabled, c.edited_at, t.title'
            . ' FROM ' . Table::ArticleCategories->value . ' c'
            . ' LEFT JOIN ' . Table::ArticleCategoryTranslations->value . ' t'
            . '  ON t.slug = c.slug AND t.locale = ?'
            . ' ORDER BY c.position ASC, c.slug ASC',
            [$locale],
        );

        return array_map(static fn(array $row): array => [
            'slug' => (string) $row['slug'],
            'title' => (string) ($row['title'] ?? $row['slug']),
            'position' => (int) $row['position'],
            'enabled' => (bool) $row['enabled'],
            'edited' => $row['edited_at'] !== null,
        ], $rows);
    }

    /**
     * One category as the editor needs it: every column, enabled or not.
     *
     * @return array{slug: string, title: string, summary: string, icon: string, accent: string, position: int, enabled: bool, edited: bool}|null
     */
    public function forEditing(string $slug, string $locale = self::DEFAULT_LOCALE): ?array
    {
        $row = $this->connection->fetchOne(
            'SELECT c.slug, c.icon, c.accent, c.position, c.enabled, c.edited_at, t.title, t.summary'
            . ' FROM ' . Table::ArticleCategories->value . ' c'
            . ' LEFT JOIN ' . Table::ArticleCategoryTranslations->value . ' t'
            . '  ON t.slug = c.slug AND t.locale = ?'
            . ' WHERE c.slug = ?',
            [$locale, $slug],
        );

        if ($row === null) {
            return null;
        }

        return [
            'slug' => (string) $row['slug'],
            'title' => (string) ($row['title'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'icon' => (string) $row['icon'],
            'accent' => (string) $row['accent'],
            'position' => (int) $row['position'],
            'enabled' => (bool) $row['enabled'],
            'edited' => $row['edited_at'] !== null,
        ];
    }

    /**
     * Show or hide one category.
     *
     * A disabled category keeps its articles: the importer checks membership
     * against `slugs()`, which is unfiltered for exactly this reason, so
     * hiding a group does not break the next import of everything in it.
     */
    public function setEnabled(string $slug, bool $enabled): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::ArticleCategories->value . ' SET enabled = ? WHERE slug = ?',
            [$enabled ? 1 : 0, $slug],
        );
    }

    /**
     * Move one category one place among the others.
     *
     * A swap rather than a step, for the reason `ArticleRepository::move()`
     * gives: positions are spaced in the seeded data and adding one to them
     * moved nothing.
     *
     * @param int $direction -1 for up, 1 for down
     */
    public function move(string $slug, int $direction): void
    {
        $siblings = array_map(
            static fn(array $sibling): string => (string) $sibling['slug'],
            $this->connection->fetchAll(
                'SELECT slug FROM ' . Table::ArticleCategories->value
                . ' ORDER BY position ASC, slug ASC',
            ),
        );

        self::reordered($siblings, $slug, $direction, $this->setPosition(...));
    }

    /**
     * One slug moved one place in a list, written back as 0..n-1.
     *
     * Shared because an article among its category and a category among the
     * others are the same operation on two tables, and two copies of it would
     * be two chances to get the edges wrong.
     *
     * @param list<string> $order the siblings, in the order they are shown
     * @param int $direction -1 for up, 1 for down
     * @param callable(string, int): void $write
     */
    private static function reordered(array $order, string $slug, int $direction, callable $write): void
    {
        $at = array_search($slug, $order, true);
        $to = $at === false ? -1 : $at + $direction;

        // Already at the end it is being asked to move towards. Nothing to do,
        // and renumbering anyway would be a write that changed nothing.
        if ($at === false || $to < 0 || $to >= count($order)) {
            return;
        }

        [$order[$at], $order[$to]] = [$order[$to], $order[$at]];

        foreach ($order as $position => $moved) {
            $write($moved, $position);
        }
    }

    /** Where one category sits among the others. */
    public function setPosition(string $slug, int $position): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::ArticleCategories->value . ' SET position = ? WHERE slug = ?',
            [max(0, $position), $slug],
        );
    }

    /**
     * Every category slug, enabled or not.
     *
     * Deliberately unfiltered, and the one read here that is. The importer
     * checks an article's category against this, and an article may belong to
     * a group that is written but held back -- refusing that would mean you
     * could not disable a category without the next import failing on every
     * article in it.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['slug'],
            $this->connection->fetchAll('SELECT slug FROM ' . Table::ArticleCategories->value),
        );
    }

    /**
     * Remove one category and the words filed under it.
     *
     * Nothing here checks for articles still pointing at it, and that is the
     * importer's job rather than this one's: it refuses the whole run when an
     * article names a category no file describes, so by the time anything gets
     * here the group really is unclaimed. A check in both places would be one
     * that could disagree.
     */
    public function delete(string $slug): void
    {
        $this->connection->execute(
            'DELETE FROM ' . Table::ArticleCategoryTranslations->value . ' WHERE slug = ?',
            [$slug],
        );
        $this->connection->execute(
            'DELETE FROM ' . Table::ArticleCategories->value . ' WHERE slug = ?',
            [$slug],
        );
    }

    /**
     * Write one category and its translation, and say whether the words moved.
     *
     * The shape ArticleRepository::store() uses, for the reason it uses it:
     * `updated_at` has to date the sentence rather than the import, so the
     * comparison happens inside the UPDATE clause and has to come first --
     * MySQL applies assignments left to right, so reading `title` after
     * assigning it would read the value just written.
     *
     * **A row somebody has edited in the panel is left alone** (A3.4, #102),
     * for the reason `ArticleRepository::store()` gives.
     *
     * @param array{icon: string, accent: string, position: int} $category
     * @param array{title: string, summary: string} $translation
     */
    public function store(
        string $slug,
        array $category,
        array $translation,
        string $locale = self::DEFAULT_LOCALE,
    ): ImportOutcome {
        if ($this->isHandEdited($slug)) {
            return ImportOutcome::Kept;
        }

        $stored = $this->connection->fetchOne(
            'SELECT title, summary FROM ' . Table::ArticleCategoryTranslations->value
            . ' WHERE slug = ? AND locale = ?',
            [$slug, $locale],
        );

        $changed = $stored === null || [
            (string) $stored['title'],
            (string) $stored['summary'],
        ] !== [
            $translation['title'],
            $translation['summary'],
        ];

        $this->connection->execute(
            'INSERT INTO ' . Table::ArticleCategories->value
            . ' (slug, icon, accent, position, enabled, created_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW())'
            . ' ON DUPLICATE KEY UPDATE icon = VALUES(icon),'
            . '  accent = VALUES(accent), position = VALUES(position)',
            [$slug, $category['icon'], $category['accent'], $category['position']],
        );

        $this->connection->execute(
            'INSERT INTO ' . Table::ArticleCategoryTranslations->value
            . ' (slug, locale, title, summary, updated_at)'
            . ' VALUES (?, ?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE'
            // First, while the old values are still readable.
            . '  updated_at = IF('
            . '   title <=> VALUES(title) AND summary <=> VALUES(summary),'
            . '   updated_at, NOW()'
            . '  ),'
            . '  title = VALUES(title), summary = VALUES(summary)',
            [$slug, $locale, $translation['title'], $translation['summary']],
        );

        return $changed ? ImportOutcome::Written : ImportOutcome::Unchanged;
    }

    /**
     * The panel's write, which takes the row away from the files.
     *
     * `ArticleRepository::edit()` says why this is its own method rather than a
     * flag (A3.4, #102).
     *
     * @param array{icon: string, accent: string, position: int} $category
     * @param array{title: string, summary: string} $translation
     */
    public function edit(
        string $slug,
        array $category,
        array $translation,
        string $locale = self::DEFAULT_LOCALE,
    ): void {
        $this->connection->execute(
            'INSERT INTO ' . Table::ArticleCategories->value
            . ' (slug, icon, accent, position, enabled, created_at, edited_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
            . ' ON DUPLICATE KEY UPDATE icon = VALUES(icon),'
            . '  accent = VALUES(accent), position = VALUES(position), edited_at = NOW()',
            [$slug, $category['icon'], $category['accent'], $category['position']],
        );

        $this->connection->execute(
            'INSERT INTO ' . Table::ArticleCategoryTranslations->value
            . ' (slug, locale, title, summary, updated_at)'
            . ' VALUES (?, ?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE'
            . '  updated_at = IF('
            . '   title <=> VALUES(title) AND summary <=> VALUES(summary),'
            . '   updated_at, NOW()'
            . '  ),'
            . '  title = VALUES(title), summary = VALUES(summary)',
            [$slug, $locale, $translation['title'], $translation['summary']],
        );
    }

    /** Whether a person has taken this row over from the files. */
    public function isHandEdited(string $slug): bool
    {
        return $this->connection->fetchValue(
            'SELECT edited_at FROM ' . Table::ArticleCategories->value . ' WHERE slug = ?',
            [$slug],
        ) !== null;
    }

    /**
     * Every slug a person owns, which is what the prune must not touch.
     *
     * @return list<string>
     */
    public function handEditedSlugs(): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['slug'],
            $this->connection->fetchAll(
                'SELECT slug FROM ' . Table::ArticleCategories->value . ' WHERE edited_at IS NOT NULL',
            ),
        );
    }

    /** Give the rows back to the files. See `ArticleRepository::reclaim()`. */
    public function reclaim(): int
    {
        return $this->connection->execute(
            'UPDATE ' . Table::ArticleCategories->value
            . ' SET edited_at = NULL WHERE edited_at IS NOT NULL',
        );
    }
}
