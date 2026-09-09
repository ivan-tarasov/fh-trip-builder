<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The help articles, which used to be an array in config/common/help.php.
 *
 * One method, because every caller wants the same thing: the whole set, keyed
 * by slug, in the order the list is meant to read. There are five articles, so
 * the page that shows one still fetches all of them -- it needs the other four
 * anyway, for the siblings in the aside.
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
