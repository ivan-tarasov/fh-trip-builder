<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

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
     * Write one category and its translation, and say whether the words moved.
     *
     * The shape ArticleRepository::store() uses, for the reason it uses it:
     * `updated_at` has to date the sentence rather than the import, so the
     * comparison happens inside the UPDATE clause and has to come first --
     * MySQL applies assignments left to right, so reading `title` after
     * assigning it would read the value just written.
     *
     * @param array{icon: string, accent: string, position: int} $category
     * @param array{title: string, summary: string} $translation
     * @return bool whether the stored words differ from the ones passed in
     */
    public function store(
        string $slug,
        array $category,
        array $translation,
        string $locale = self::DEFAULT_LOCALE,
    ): bool {
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

        return $changed;
    }
}
