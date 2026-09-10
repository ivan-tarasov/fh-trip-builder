<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The help articles, which used to be an array in config/common/help.php.
 *
 * `all()` is what almost everything wants: the whole set, keyed by slug, in the
 * order the list is meant to read, and without the prose. The page showing one
 * article calls it too -- it needs the other four for the siblings in the aside
 * -- and then `find()` for the one body it is actually going to print.
 *
 * Not memoised, which means a help page reads these five rows twice -- once
 * for the article and its siblings, once for the footer's column, since the
 * controller and LayoutData hold separate repositories. That is deliberate.
 * Money and Connection both keep static memos, so the pattern exists here, but
 * a repository that caches its own reads is a repository that cannot see a
 * write made after it -- and the importer that arrives with the article bodies
 * reads and writes in the same process. Five rows twice is cheaper than that
 * bug. The footer's column now costs one query, like the five beside it.
 *
 * The shape returned is deliberately the shape the config array had: `title`,
 * `short`, `icon`, `summary`, keyed by slug. That is what let the four readers
 * change where they get articles from without changing what they do with them,
 * and it is why `short` comes back as null rather than being absent -- the
 * footer's `$article['short'] ?? $article['title']` behaves the same either
 * way.
 */
final readonly class ArticleRepository
{
    /**
     * The language every read asks for, until there is a second one.
     *
     * This is the line the language work replaces. Nothing else in the app has
     * a locale concept yet, so resolving one from a request, a cookie or a
     * path would be inventing an answer to a question nobody is asking --
     * whereas a named constant is one place to look when somebody does.
     */
    public const string DEFAULT_LOCALE = 'en';

    public function __construct(private Connection $connection) {}

    /**
     * One article, with its prose, or null if there is no such article.
     *
     * Separate from all() because the body is the only large column here and
     * almost nobody wants it: the hub lists five summaries, the footer wants
     * five labels, the sitemap wants five slugs, and the aside wants four
     * titles. Putting the body in all() would pull ten kilobytes of markdown
     * into the footer of every page on the site.
     *
     * @return array{title: string, short: ?string, icon: string, summary: string, body: string, updated_at: string}|null
     */
    public function find(string $slug, string $locale = self::DEFAULT_LOCALE): ?array
    {
        $row = $this->connection->fetchOne(
            'SELECT a.icon, t.title, t.short, t.summary, t.body, t.updated_at'
            . ' FROM ' . Table::Articles->value . ' a'
            . ' JOIN ' . Table::ArticleTranslations->value . ' t ON t.slug = a.slug'
            . ' WHERE a.slug = ? AND a.enabled = 1 AND t.locale = ?',
            [$slug, $locale],
        );

        if ($row === null) {
            return null;
        }

        return [
            'title' => (string) $row['title'],
            'short' => $row['short'] === null ? null : (string) $row['short'],
            'icon' => (string) $row['icon'],
            'summary' => (string) $row['summary'],
            'body' => (string) $row['body'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Write one article and its translation, and say whether anything changed.
     *
     * `updated_at` is the reason this is not two plain upserts. It has to date
     * the words rather than the import, so it moves only where a translated
     * column actually differs -- otherwise every install would claim every
     * article had just been rewritten, and the date would be worth nothing.
     *
     * The comparison happens inside the UPDATE clause and has to come first:
     * MySQL applies the assignments left to right, so reading `title` after
     * assigning it would read the value just written and never see a change.
     * `<=>` and not `=`, because `short` is nullable and NULL = NULL is not
     * true.
     *
     * @param array{icon: string, position: int} $article
     * @param array{title: string, short: ?string, summary: string, body: string} $translation
     * @return bool whether the stored words differ from the ones passed in
     */
    public function store(
        string $slug,
        array $article,
        array $translation,
        string $locale = self::DEFAULT_LOCALE,
    ): bool {
        $stored = $this->connection->fetchOne(
            'SELECT title, short, summary, body FROM ' . Table::ArticleTranslations->value
            . ' WHERE slug = ? AND locale = ?',
            [$slug, $locale],
        );

        $changed = $stored === null || [
            (string) $stored['title'],
            $stored['short'] === null ? null : (string) $stored['short'],
            (string) $stored['summary'],
            (string) $stored['body'],
        ] !== [
            $translation['title'],
            $translation['short'],
            $translation['summary'],
            $translation['body'],
        ];

        $this->connection->execute(
            'INSERT INTO ' . Table::Articles->value . ' (slug, icon, position, enabled, created_at)'
            . ' VALUES (?, ?, ?, 1, NOW())'
            . ' ON DUPLICATE KEY UPDATE icon = VALUES(icon), position = VALUES(position)',
            [$slug, $article['icon'], $article['position']],
        );

        $this->connection->execute(
            'INSERT INTO ' . Table::ArticleTranslations->value
            . ' (slug, locale, title, short, summary, body, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE'
            // First, while the old values are still readable.
            . '  updated_at = IF('
            . '   title <=> VALUES(title) AND short <=> VALUES(short)'
            . '   AND summary <=> VALUES(summary) AND body <=> VALUES(body),'
            . '   updated_at, NOW()'
            . '  ),'
            . '  title = VALUES(title), short = VALUES(short),'
            . '  summary = VALUES(summary), body = VALUES(body)',
            [
                $slug,
                $locale,
                $translation['title'],
                $translation['short'],
                $translation['summary'],
                $translation['body'],
            ],
        );

        return $changed;
    }

    /**
     * Every article on offer, keyed by slug, lowest `position` first.
     *
     * `enabled` is filtered here rather than by each caller, which is what
     * makes holding an article back actually hold it back: it leaves the hub,
     * the aside, the sitemap, the footer column and the vote endpoint's
     * allow-list in one go.
     *
     * The order is not cosmetic. It is the only order the hub's cards, the
     * aside's siblings and the sitemap have, and it is the tiebreaker the
     * footer column falls back on when nobody has voted -- which, with no
     * seeder for article_votes, is every fresh install.
     *
     * @return array<string, array{title: string, short: ?string, icon: string, summary: string}>
     */
    public function all(string $locale = self::DEFAULT_LOCALE): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT a.slug, a.icon, t.title, t.short, t.summary'
            . ' FROM ' . Table::Articles->value . ' a'
            . ' JOIN ' . Table::ArticleTranslations->value . ' t ON t.slug = a.slug'
            . ' WHERE a.enabled = 1 AND t.locale = ?'
            // Slug second so two articles sharing a position still come back in
            // a fixed order, rather than whichever the storage engine offers.
            . ' ORDER BY a.position ASC, a.slug ASC',
            [$locale],
        );

        $articles = [];

        foreach ($rows as $row) {
            $articles[(string) $row['slug']] = [
                'title' => (string) $row['title'],
                // Null and not '' -- see the class note. A cast would turn the
                // three articles whose titles fit into empty labels.
                'short' => $row['short'] === null ? null : (string) $row['short'],
                'icon' => (string) $row['icon'],
                'summary' => (string) $row['summary'],
            ];
        }

        return $articles;
    }
}
