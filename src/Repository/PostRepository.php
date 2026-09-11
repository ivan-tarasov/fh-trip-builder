<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The Airside posts: travel writing, as opposed to help.
 *
 * Deliberately the same shape as ArticleRepository, because the two families
 * share their machinery and only their ordering really differs -- help by a
 * `position` somebody chooses, Airside by the date a post already has. Reading
 * one after the other should not require learning a second idiom.
 *
 * Not memoised, for the reason ArticleRepository gives at length: the importer
 * reads and writes in the same process, and a repository that caches its own
 * reads cannot see a write made after it.
 */
final readonly class PostRepository
{
    /**
     * The language every read asks for, until there is a second one.
     *
     * The sibling of ArticleRepository::DEFAULT_LOCALE, and the second line
     * the language work replaces. Two constants rather than one shared
     * somewhere else, because a locale is a property of a family of content:
     * help could be translated into five languages and Airside into one.
     */
    public const string DEFAULT_LOCALE = 'en';

    public function __construct(private Connection $connection) {}

    /**
     * One post, with its prose, or null if there is no such post.
     *
     * Separate from all() for the reason it is separate there: the body is the
     * only large column, and the hub wants none of them.
     *
     * @return array{title: string, summary: string, author: string, hero: ?string, hero_alt: ?string, body: string, published_at: string, updated_at: string}|null
     */
    public function find(string $slug, string $locale = self::DEFAULT_LOCALE): ?array
    {
        $row = $this->connection->fetchOne(
            'SELECT p.author, p.hero, p.published_at,'
            . ' t.title, t.summary, t.hero_alt, t.body, t.updated_at'
            . ' FROM ' . Table::Posts->value . ' p'
            . ' JOIN ' . Table::PostTranslations->value . ' t ON t.slug = p.slug'
            . ' WHERE p.slug = ? AND p.enabled = 1 AND t.locale = ?',
            [$slug, $locale],
        );

        if ($row === null) {
            return null;
        }

        return [
            'title' => (string) $row['title'],
            'summary' => (string) $row['summary'],
            'author' => (string) $row['author'],
            'hero' => $row['hero'] === null ? null : (string) $row['hero'],
            'hero_alt' => $row['hero_alt'] === null ? null : (string) $row['hero_alt'],
            'body' => (string) $row['body'],
            'published_at' => (string) $row['published_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Every post on offer, keyed by slug, newest first.
     *
     * `enabled` is filtered here rather than by each caller, which is what
     * makes holding a post back actually hold it back.
     *
     * `slug` breaks the tie after the date, and it has to: three posts
     * imported on one day share a `published_at` to the second, and without a
     * second key MySQL is free to order them differently between two reads --
     * which would move cards around the hub for no reason a reader could see.
     *
     * @return array<string, array{title: string, summary: string, author: string, hero: ?string, hero_alt: ?string, published_at: string}>
     */
    public function all(string $locale = self::DEFAULT_LOCALE): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT p.slug, p.author, p.hero, p.published_at, t.title, t.summary, t.hero_alt'
            . ' FROM ' . Table::Posts->value . ' p'
            . ' JOIN ' . Table::PostTranslations->value . ' t ON t.slug = p.slug'
            . ' WHERE p.enabled = 1 AND t.locale = ?'
            . ' ORDER BY p.published_at DESC, p.slug',
            [$locale],
        );

        return self::hydrate($rows);
    }

    /**
     * Every post carrying one tag, newest first.
     *
     * The listing page behind a pill. Shaped exactly like `all()`, so the hub's
     * card partial draws this list without knowing it was filtered.
     *
     * @return array<string, array{title: string, summary: string, author: string, hero: ?string, hero_alt: ?string, published_at: string}>
     */
    public function taggedWith(string $tag, string $locale = self::DEFAULT_LOCALE): array
    {
        return self::hydrate($this->connection->fetchAll(
            'SELECT p.slug, p.author, p.hero, p.published_at, t.title, t.summary, t.hero_alt'
            . ' FROM ' . Table::Posts->value . ' p'
            . ' JOIN ' . Table::PostTranslations->value . ' t ON t.slug = p.slug'
            . ' JOIN ' . Table::PostTagMap->value . ' m ON m.post = p.slug'
            . ' WHERE m.tag = ? AND p.enabled = 1 AND t.locale = ?'
            . ' ORDER BY p.published_at DESC, p.slug',
            [$tag, $locale],
        ));
    }

    /**
     * Other posts sharing a tag with this one, newest first.
     *
     * `DISTINCT` because two posts can share more than one tag, and without it
     * a post appears once per tag they have in common -- which would also make
     * the limit count duplicates rather than posts.
     *
     * The post itself is excluded rather than filtered afterwards, so the limit
     * is a limit on what is actually offered.
     *
     * @return array<string, array{title: string, summary: string, author: string, hero: ?string, hero_alt: ?string, published_at: string}>
     */
    public function related(string $slug, int $limit = 3, string $locale = self::DEFAULT_LOCALE): array
    {
        if ($limit < 1) {
            return [];
        }

        return self::hydrate($this->connection->fetchAll(
            'SELECT DISTINCT p.slug, p.author, p.hero, p.published_at, t.title, t.summary, t.hero_alt'
            . ' FROM ' . Table::PostTagMap->value . ' mine'
            . ' JOIN ' . Table::PostTagMap->value . ' theirs'
            . '  ON theirs.tag = mine.tag AND theirs.post <> mine.post'
            . ' JOIN ' . Table::Posts->value . ' p ON p.slug = theirs.post'
            . ' JOIN ' . Table::PostTranslations->value . ' t ON t.slug = p.slug'
            . ' WHERE mine.post = ? AND p.enabled = 1 AND t.locale = ?'
            . ' ORDER BY p.published_at DESC, p.slug'
            . ' LIMIT ' . $limit,
            [$slug, $locale],
        ));
    }

    /**
     * Named posts, in the order they were named.
     *
     * For a caller that has already decided which posts it wants and in what
     * order -- the vote table decides the most-liked block, and knows nothing
     * about titles. Reading `all()` and picking from it would work at four
     * posts and pull the whole section into memory at four hundred.
     *
     * The order is the caller's, restored here: `IN (...)` returns rows in
     * whatever order the engine likes, so sorting by anything of this table's
     * own would quietly discard the ranking that was the point.
     *
     * @param list<string> $slugs
     * @return array<string, array{title: string, summary: string, author: string, hero: ?string, hero_alt: ?string, published_at: string}>
     */
    public function bySlugs(array $slugs, string $locale = self::DEFAULT_LOCALE): array
    {
        $slugs = array_values(array_unique($slugs));

        if ($slugs === []) {
            return [];
        }

        $found = self::hydrate($this->connection->fetchAll(
            'SELECT p.slug, p.author, p.hero, p.published_at, t.title, t.summary, t.hero_alt'
            . ' FROM ' . Table::Posts->value . ' p'
            . ' JOIN ' . Table::PostTranslations->value . ' t ON t.slug = p.slug'
            . ' WHERE p.enabled = 1 AND t.locale = ?'
            . ' AND p.slug IN (' . implode(', ', array_fill(0, count($slugs), '?')) . ')',
            [$locale, ...$slugs],
        ));

        $ordered = [];

        foreach ($slugs as $slug) {
            // A slug with no row is a post that was disabled or deleted since
            // whoever asked last looked. Skipped rather than faked.
            if (isset($found[$slug])) {
                $ordered[$slug] = $found[$slug];
            }
        }

        return $ordered;
    }

    /**
     * One row shape, written once.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{title: string, summary: string, author: string, hero: ?string, hero_alt: ?string, published_at: string}>
     */
    private static function hydrate(array $rows): array
    {
        $posts = [];

        foreach ($rows as $row) {
            $posts[(string) $row['slug']] = [
                'title' => (string) $row['title'],
                'summary' => (string) $row['summary'],
                'author' => (string) $row['author'],
                'hero' => $row['hero'] === null ? null : (string) $row['hero'],
                'hero_alt' => $row['hero_alt'] === null ? null : (string) $row['hero_alt'],
                'published_at' => (string) $row['published_at'],
            ];
        }

        return $posts;
    }

    /**
     * Every post slug, enabled or not.
     *
     * Unfiltered on purpose, and the only read here that is: the importer
     * compares this against the files on disk to find rows nothing describes
     * any more, and a post held back with `enabled = 0` is still a row whose
     * file may have gone.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['slug'],
            $this->connection->fetchAll('SELECT slug FROM ' . Table::Posts->value),
        );
    }

    /**
     * Write one post and its translation, and say whether anything changed.
     *
     * `updated_at` has to date the words rather than the import, so it moves
     * only where a translated column actually differs. The comparison happens
     * inside the UPDATE clause and has to come first: MySQL applies the
     * assignments left to right, so reading `title` after assigning it would
     * read the value just written and never see a change. `<=>` and not `=`,
     * because `hero_alt` is nullable and NULL = NULL is not true.
     *
     * `published_at` is a stored value and not `NOW()`. It is what the post
     * claims, the header names it, and the section's whole order depends on
     * it -- so re-importing must not silently republish everything as today.
     *
     * @param array{published_at: string, author: string, hero: ?string} $post
     * @param array{title: string, summary: string, hero_alt: ?string, body: string} $translation
     * @return bool whether the stored words differ from the ones passed in
     */
    public function store(
        string $slug,
        array $post,
        array $translation,
        string $locale = self::DEFAULT_LOCALE,
    ): bool {
        $stored = $this->connection->fetchOne(
            'SELECT title, summary, hero_alt, body FROM ' . Table::PostTranslations->value
            . ' WHERE slug = ? AND locale = ?',
            [$slug, $locale],
        );

        $changed = $stored === null || [
            (string) $stored['title'],
            (string) $stored['summary'],
            $stored['hero_alt'] === null ? null : (string) $stored['hero_alt'],
            (string) $stored['body'],
        ] !== [
            $translation['title'],
            $translation['summary'],
            $translation['hero_alt'],
            $translation['body'],
        ];

        $this->connection->execute(
            'INSERT INTO ' . Table::Posts->value
            . ' (slug, published_at, author, hero, enabled, created_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW())'
            . ' ON DUPLICATE KEY UPDATE published_at = VALUES(published_at),'
            . '  author = VALUES(author), hero = VALUES(hero)',
            [$slug, $post['published_at'], $post['author'], $post['hero']],
        );

        $this->connection->execute(
            'INSERT INTO ' . Table::PostTranslations->value
            . ' (slug, locale, title, summary, hero_alt, body, updated_at)'
            // The publication date and not NOW() on a first insert. A post
            // written in March and imported in September was not edited in
            // September -- the words are the published words, and the page
            // prints "updated" only where this is later than publication. With
            // NOW() here every backdated post claimed an edit it never had, on
            // the day it was first imported.
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            // First, while the old values are still readable.
            . '  updated_at = IF('
            . '   title <=> VALUES(title) AND summary <=> VALUES(summary)'
            . '   AND hero_alt <=> VALUES(hero_alt) AND body <=> VALUES(body),'
            . '   updated_at, NOW()'
            . '  ),'
            . '  title = VALUES(title), summary = VALUES(summary),'
            . '  hero_alt = VALUES(hero_alt), body = VALUES(body)',
            [
                $slug,
                $locale,
                $translation['title'],
                $translation['summary'],
                $translation['hero_alt'],
                $translation['body'],
                $post['published_at'],
            ],
        );

        return $changed;
    }

    /**
     * Remove one post and the words filed under it.
     *
     * Both tables, because a translation with no post is a row nothing can
     * reach and nothing would ever tidy: every read here starts from `posts`
     * and joins outwards.
     */
    public function delete(string $slug): void
    {
        $this->connection->execute(
            'DELETE FROM ' . Table::PostTranslations->value . ' WHERE slug = ?',
            [$slug],
        );
        $this->connection->execute(
            'DELETE FROM ' . Table::Posts->value . ' WHERE slug = ?',
            [$slug],
        );
    }
}
