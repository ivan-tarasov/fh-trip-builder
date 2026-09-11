<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The tags on Airside posts, and the map between them.
 *
 * Tag *names* and the map live here. Reads that come back as posts --
 * `taggedWith()` and `related()` -- are on PostRepository instead, because
 * they return the post shape and one place should own it. So both classes
 * touch `post_tag_map`, which is the price of not duplicating that shape.
 *
 * Not memoised, for the reason ArticleRepository gives at length: the importer
 * reads and writes in the same process.
 */
final readonly class PostTagRepository
{
    public const string DEFAULT_LOCALE = 'en';

    public function __construct(private Connection $connection) {}

    /**
     * What a tag is called, or null if there is no such tag.
     *
     * The listing route's 404: a URL naming a tag nobody used is a page that
     * does not exist, not an empty one.
     */
    public function name(string $tag, string $locale = self::DEFAULT_LOCALE): ?string
    {
        $row = $this->connection->fetchOne(
            'SELECT name FROM ' . Table::PostTagTranslations->value
            . ' WHERE slug = ? AND locale = ?',
            [$tag, $locale],
        );

        return $row === null ? null : (string) $row['name'];
    }

    /**
     * The tags on one post, as slug => name, in the order the pills read.
     *
     * By name and not by insertion: a file listing its tags in a different
     * order on a later import would otherwise reorder the pills for no reason
     * a reader could see.
     *
     * @return array<string, string>
     */
    public function forPost(string $slug, string $locale = self::DEFAULT_LOCALE): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT m.tag, t.name FROM ' . Table::PostTagMap->value . ' m'
            . ' JOIN ' . Table::PostTagTranslations->value . ' t'
            . '  ON t.slug = m.tag AND t.locale = ?'
            . ' WHERE m.post = ? ORDER BY t.name',
            [$locale, $slug],
        );

        $tags = [];

        foreach ($rows as $row) {
            $tags[(string) $row['tag']] = (string) $row['name'];
        }

        return $tags;
    }

    /**
     * Every tag in use, as slug => name, with how many posts carry it.
     *
     * The join is inner on purpose: a name with no post behind it is a tag the
     * importer failed to prune, and listing it would offer a reader an empty
     * page.
     *
     * @return array<string, array{name: string, posts: int}>
     */
    public function inUse(string $locale = self::DEFAULT_LOCALE): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT t.slug, t.name, COUNT(m.post) AS posts'
            . ' FROM ' . Table::PostTagTranslations->value . ' t'
            . ' JOIN ' . Table::PostTagMap->value . ' m ON m.tag = t.slug'
            . ' WHERE t.locale = ?'
            . ' GROUP BY t.slug, t.name ORDER BY t.name',
            [$locale],
        );

        $tags = [];

        foreach ($rows as $row) {
            $tags[(string) $row['slug']] = [
                'name' => (string) $row['name'],
                'posts' => (int) $row['posts'],
            ];
        }

        return $tags;
    }

    /**
     * Write one tag's name.
     */
    public function store(string $tag, string $name, string $locale = self::DEFAULT_LOCALE): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::PostTagTranslations->value . ' (slug, locale, name)'
            . ' VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)',
            [$tag, $locale, $name],
        );
    }

    /**
     * Make one post's tags exactly this set.
     *
     * Delete then insert, rather than working out a difference: the set is at
     * most a handful of rows, and a rewrite cannot leave a tag behind the way a
     * missed comparison can.
     *
     * @param list<string> $tags
     */
    public function map(string $post, array $tags): void
    {
        $this->connection->execute(
            'DELETE FROM ' . Table::PostTagMap->value . ' WHERE post = ?',
            [$post],
        );

        foreach (array_unique($tags) as $tag) {
            $this->connection->execute(
                'INSERT INTO ' . Table::PostTagMap->value . ' (post, tag) VALUES (?, ?)',
                [$post, $tag],
            );
        }
    }

    /**
     * Forget every tag no post carries any more, and say how many.
     *
     * Names outlive the map otherwise: removing the last post that used a tag
     * leaves its name behind, and the next post to use that slug would inherit
     * whatever it was called before.
     */
    public function pruneUnused(): int
    {
        return $this->connection->execute(
            'DELETE FROM ' . Table::PostTagTranslations->value
            . ' WHERE slug NOT IN (SELECT tag FROM ' . Table::PostTagMap->value . ')',
        );
    }

    /**
     * Remove one post from the map, for when the post itself goes.
     */
    public function forget(string $post): void
    {
        $this->connection->execute(
            'DELETE FROM ' . Table::PostTagMap->value . ' WHERE post = ?',
            [$post],
        );
    }
}
