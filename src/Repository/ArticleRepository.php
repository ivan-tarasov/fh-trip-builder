<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\ImportOutcome;

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
 *
 * @phpstan-type ArticleRow array{title: string, short: ?string, icon: string, category: string, summary: string}
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
     * @return array{title: string, short: ?string, icon: string, category: string, summary: string, body: string, updated_at: string}|null
     */
    public function find(string $slug, string $locale = self::DEFAULT_LOCALE): ?array
    {
        /** @var array{icon: string, category: string, title: string, short: string|null, summary: string, body: string, updated_at: string}|null $row */
        $row = $this->connection->fetchOne(
            'SELECT a.icon, a.category, t.title, t.short, t.summary, t.body, t.updated_at'
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
            'category' => (string) $row['category'],
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
     * **A row somebody has edited in the panel is left alone.** That is A3.4
     * (#102): the importer owns a row until a person opens it and saves, and
     * after that the row is theirs. Without it the panel was a place to type
     * things that the next deploy quietly undid.
     *
     * @param array{category: string, icon: string, position: int} $article
     * @param array{title: string, short: ?string, summary: string, body: string} $translation
     */
    public function store(
        string $slug,
        array $article,
        array $translation,
        string $locale = self::DEFAULT_LOCALE,
    ): ImportOutcome {
        if ($this->isHandEdited($slug)) {
            return ImportOutcome::Kept;
        }

        /** @var array{title: string, short: string|null, summary: string, body: string}|null $stored */
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
            'INSERT INTO ' . Table::Articles->value
            . ' (slug, category, icon, position, enabled, created_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW())'
            . ' ON DUPLICATE KEY UPDATE category = VALUES(category),'
            . '  icon = VALUES(icon), position = VALUES(position)',
            [$slug, $article['category'], $article['icon'], $article['position']],
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

        return $changed ? ImportOutcome::Written : ImportOutcome::Unchanged;
    }

    /**
     * The panel's write, which takes the row away from the files.
     *
     * The same two statements as `store()` and one more, and the one more is
     * the point: `edited_at` is what tells the next import to leave this alone.
     * A separate method rather than a flag on `store()`, because a boolean at a
     * call site says nothing about who is writing and this is entirely a
     * question of who is writing (A3.4, #102).
     *
     * @param array{category: string, icon: string, position: int} $article
     * @param array{title: string, short: ?string, summary: string, body: string} $translation
     */
    public function edit(
        string $slug,
        array $article,
        array $translation,
        string $locale = self::DEFAULT_LOCALE,
    ): void {
        $this->connection->execute(
            'INSERT INTO ' . Table::Articles->value
            . ' (slug, category, icon, position, enabled, created_at, edited_at)'
            . ' VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
            . ' ON DUPLICATE KEY UPDATE category = VALUES(category),'
            . '  icon = VALUES(icon), position = VALUES(position), edited_at = NOW()',
            [$slug, $article['category'], $article['icon'], $article['position']],
        );

        $this->connection->execute(
            'INSERT INTO ' . Table::ArticleTranslations->value
            . ' (slug, locale, title, short, summary, body, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE'
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
    }

    /** Whether a person has taken this row over from the files. */
    public function isHandEdited(string $slug): bool
    {
        return $this->connection->fetchValue(
            'SELECT edited_at FROM ' . Table::Articles->value . ' WHERE slug = ?',
            [$slug],
        ) !== null;
    }

    /**
     * Every slug a person owns, which is what the prune must not touch.
     *
     * An article written in the panel has no file at all, so the prune would
     * read it as one the files had dropped and delete it -- taking somebody's
     * work with it on the next deploy.
     *
     * @return list<string>
     */
    public function handEditedSlugs(): array
    {
        /** @var list<array{slug: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT slug FROM ' . Table::Articles->value . ' WHERE edited_at IS NOT NULL',
        );

        return array_column($rows, 'slug');
    }

    /**
     * Give the rows back to the files.
     *
     * What `articles:import --force` does before it writes. Clearing the stamp
     * rather than writing through it keeps one rule in one place: the importer
     * still refuses a row a person owns, and this is how a row stops being one.
     */
    public function reclaim(): int
    {
        return $this->connection->execute(
            'UPDATE ' . Table::Articles->value . ' SET edited_at = NULL WHERE edited_at IS NOT NULL',
        );
    }

    /**
     * Every article the panel can edit, disabled ones included.
     *
     * `all()` cannot be reused and that is the point of it: it filters
     * `enabled = 1`, which is what makes holding an article back actually hold
     * it back everywhere at once. The panel is the one reader that has to see
     * what it is hiding (A3.3, #101).
     *
     * @return list<array{slug: string, title: string, category: string, position: int, enabled: bool, edited: bool, updated_at: string}>
     */
    public function forPanel(string $locale = self::DEFAULT_LOCALE): array
    {
        /** @var list<array{slug: string, category: string, position: int, enabled: int, edited_at: string|null, title: string|null, updated_at: string|null}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT a.slug, a.category, a.position, a.enabled, a.edited_at, t.title, t.updated_at'
            . ' FROM ' . Table::Articles->value . ' a'
            // LEFT, for the same reason `all()` gives: a row with no
            // translation in this locale is still an article somebody has to
            // be able to find and fix, and an inner join would hide it.
            . ' LEFT JOIN ' . Table::ArticleTranslations->value . ' t'
            . '  ON t.slug = a.slug AND t.locale = ?'
            . ' ORDER BY a.category ASC, a.position ASC, a.slug ASC',
            [$locale],
        );

        return array_map(static fn(array $row): array => [
            'slug' => (string) $row['slug'],
            'title' => (string) ($row['title'] ?? $row['slug']),
            'category' => (string) $row['category'],
            'position' => (int) $row['position'],
            'enabled' => (bool) $row['enabled'],
            // Whether the files still own this row, which decides what the next
            // `articles:import` does to it (A3.4, #102).
            'edited' => $row['edited_at'] !== null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ], $rows);
    }

    /**
     * One article as the editor needs it: every column, enabled or not.
     *
     * @return array{slug: string, title: string, short: ?string, icon: string, category: string, summary: string, body: string, position: int, enabled: bool, edited: bool}|null
     */
    public function forEditing(string $slug, string $locale = self::DEFAULT_LOCALE): ?array
    {
        /** @var array{slug: string, icon: string, category: string, position: int, enabled: int, edited_at: string|null, title: string|null, short: string|null, summary: string|null, body: string|null}|null $row */
        $row = $this->connection->fetchOne(
            'SELECT a.slug, a.icon, a.category, a.position, a.enabled, a.edited_at,'
            . ' t.title, t.short, t.summary, t.body'
            . ' FROM ' . Table::Articles->value . ' a'
            . ' LEFT JOIN ' . Table::ArticleTranslations->value . ' t'
            . '  ON t.slug = a.slug AND t.locale = ?'
            . ' WHERE a.slug = ?',
            [$locale, $slug],
        );

        if ($row === null) {
            return null;
        }

        return [
            'slug' => (string) $row['slug'],
            'title' => (string) ($row['title'] ?? ''),
            'short' => $row['short'] === null ? null : (string) $row['short'],
            'icon' => (string) $row['icon'],
            'category' => (string) $row['category'],
            'summary' => (string) ($row['summary'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'position' => (int) $row['position'],
            'enabled' => (bool) $row['enabled'],
            'edited' => $row['edited_at'] !== null,
        ];
    }

    /**
     * Show or hide one article.
     *
     * Its own statement rather than a field on `store()`, because the two are
     * different acts: `store()` is the importer writing words, and this is
     * somebody deciding whether a finished page is on the site. Keeping them
     * apart is also what stops an import quietly re-enabling something that
     * was held back.
     */
    public function setEnabled(string $slug, bool $enabled): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::Articles->value . ' SET enabled = ? WHERE slug = ?',
            [$enabled ? 1 : 0, $slug],
        );
    }

    /**
     * Move one article one place among the others in its category.
     *
     * A swap, not a step: `position` is spaced 10, 20, 30 in the seeded data,
     * so adding one to it moved nothing and the button looked broken. Both
     * neighbours are renumbered so the order is always well defined -- two
     * articles sharing a position fall back to the slug, which is not an order
     * anybody chose.
     *
     * @param int $direction -1 for up, 1 for down
     */
    public function move(string $slug, int $direction): void
    {
        /** @var array{category: string}|null $row */
        $row = $this->connection->fetchOne(
            'SELECT category FROM ' . Table::Articles->value . ' WHERE slug = ?',
            [$slug],
        );

        if ($row === null) {
            return;
        }

        /** @var list<array{slug: string}> $siblingRows */
        $siblingRows = $this->connection->fetchAll(
            'SELECT slug FROM ' . Table::Articles->value
            . ' WHERE category = ? ORDER BY position ASC, slug ASC',
            [$row['category']],
        );

        self::reordered(array_column($siblingRows, 'slug'), $slug, $direction, $this->setPosition(...));
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

    /** Where one article sits among its siblings. */
    public function setPosition(string $slug, int $position): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::Articles->value . ' SET position = ? WHERE slug = ?',
            [max(0, $position), $slug],
        );
    }

    /**
     * Every article slug, enabled or not.
     *
     * Unfiltered on purpose, and the only read here that is. The importer
     * compares this against the files on disk to find rows nothing describes
     * any more, and an article held back with `enabled = 0` is still a row
     * whose file may have gone.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        /** @var list<array{slug: string}> $rows */
        $rows = $this->connection->fetchAll('SELECT slug FROM ' . Table::Articles->value);

        return array_column($rows, 'slug');
    }

    /**
     * Remove one article and the words filed under it.
     *
     * Both tables, because a translation with no article is a row nothing can
     * reach and nothing would ever tidy: every read here starts from
     * `articles` and joins outwards. The votes cast on it are
     * ArticleVoteRepository's to remove, and it says why they cannot stay.
     */
    public function delete(string $slug): void
    {
        $this->connection->execute(
            'DELETE FROM ' . Table::ArticleTranslations->value . ' WHERE slug = ?',
            [$slug],
        );
        $this->connection->execute(
            'DELETE FROM ' . Table::Articles->value . ' WHERE slug = ?',
            [$slug],
        );
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
     * @return array<string, ArticleRow>
     */
    public function all(string $locale = self::DEFAULT_LOCALE): array
    {
        /** @var list<array{slug: string, icon: string, category: string, title: string, short: string|null, summary: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT a.slug, a.icon, a.category, t.title, t.short, t.summary'
            . ' FROM ' . Table::Articles->value . ' a'
            . ' JOIN ' . Table::ArticleTranslations->value . ' t ON t.slug = a.slug'
            // LEFT, and deliberately. There is no foreign key here -- this
            // schema has none anywhere -- so `category` can name a row that
            // does not exist, and an inner join would answer that by dropping
            // the article out of the footer, the sitemap and every aside at
            // once. Losing a page silently is a worse failure than showing it
            // in an odd place, so an orphan stays in this list and the hub,
            // which is the one reader that must group, is where it is handled.
            // The importer refuses an unknown category, so this can only come
            // from something editing the table directly.
            . ' LEFT JOIN ' . Table::ArticleCategories->value . ' c ON c.slug = a.category'
            . ' WHERE a.enabled = 1 AND t.locale = ?'
            // Category first, so a caller can walk this once and get its
            // groups in order without sorting again. `IS NULL` ahead of it
            // because MySQL sorts NULL first ascending, which would put an
            // orphan at the top of the footer column. Slug last so two
            // articles sharing a position still come back in a fixed order
            // rather than whichever the storage engine offers.
            . ' ORDER BY c.position IS NULL ASC, c.position ASC, a.position ASC, a.slug ASC',
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
                'category' => (string) $row['category'],
                'summary' => (string) $row['summary'],
            ];
        }

        return $articles;
    }
}
