<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Votes on Airside posts.
 *
 * The sibling of ArticleVoteRepository, deliberately the same shape: the
 * thumbs, the tally and the verdict are the same three questions asked of a
 * different table, and reading one after the other should not require learning
 * a second idiom.
 *
 * One method is new -- `liked()` -- because posts have a block that articles
 * do not. It is also the one place the difference between these two families
 * shows: an article's votes order a footer column that always renders, and a
 * post's order a block that does not render at all until somebody has voted.
 */
final readonly class PostVoteRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Record one reader's verdict, or change the one they already gave.
     *
     * `ON DUPLICATE KEY` and not an insert: the key is `slug, voter`, so a
     * reader who changes their mind moves their own row rather than adding a
     * second one and voting twice.
     */
    public function record(string $slug, string $voter, bool $helpful): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::PostVotes->value . ' (slug, voter, helpful, voted_at)'
            . ' VALUES (?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE helpful = VALUES(helpful), voted_at = NOW()',
            [$slug, $voter, $helpful ? 1 : 0],
        );
    }

    /**
     * Forget every vote on one post, for when the post itself goes.
     *
     * Keyed on the slug, so a later post reusing a retired one would otherwise
     * inherit a verdict readers gave to something else.
     */
    public function delete(string $slug): void
    {
        $this->connection->execute(
            'DELETE FROM ' . Table::PostVotes->value . ' WHERE slug = ?',
            [$slug],
        );
    }

    /**
     * What one post's readers said.
     *
     * @return array{votes: int, helpful: int}
     */
    public function tallyFor(string $slug): array
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS votes, SUM(helpful) AS helpful'
            . ' FROM ' . Table::PostVotes->value . ' WHERE slug = ?',
            [$slug],
        );

        return [
            'votes' => (int) ($row['votes'] ?? 0),
            'helpful' => (int) ($row['helpful'] ?? 0),
        ];
    }

    /**
     * Whether this reader already voted, and which way.
     *
     * Null means they have not, which the page needs to tell apart from a
     * thumbs-down: one draws no selection and the other draws one.
     */
    public function verdictOf(string $slug, string $voter): ?bool
    {
        $row = $this->connection->fetchOne(
            'SELECT helpful FROM ' . Table::PostVotes->value . ' WHERE slug = ? AND voter = ?',
            [$slug, $voter],
        );

        return $row === null ? null : (bool) $row['helpful'];
    }

    /**
     * The posts readers liked, best first, and **only those with a vote**.
     *
     * The filter is the honest part. There is no seeder for this table on
     * purpose, so on a fresh install every post is level and whatever breaks
     * the tie would decide the whole block -- the trap FooterRenderTest
     * documents for the footer's help column. Rather than pick a better
     * tiebreaker, this returns nothing until somebody has voted, and the block
     * does not draw. A heading over an arbitrary list is worse than no
     * heading.
     *
     * Ordered by yeses and not by a ratio: with the handful of votes a site
     * this size collects, one thumbs-down would swing a ratio further than any
     * amount of approval could. `votes` breaks the tie, then `slug`, so the
     * order cannot shift between two identical reads.
     *
     * @return array<string, array{votes: int, helpful: int}>
     */
    public function liked(int $limit = 3): array
    {
        if ($limit < 1) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT slug, COUNT(*) AS votes, SUM(helpful) AS helpful'
            . ' FROM ' . Table::PostVotes->value
            . ' GROUP BY slug'
            . ' HAVING helpful > 0'
            . ' ORDER BY helpful DESC, votes DESC, slug'
            . ' LIMIT ' . $limit,
        );

        $liked = [];

        foreach ($rows as $row) {
            $liked[(string) $row['slug']] = [
                'votes' => (int) $row['votes'],
                'helpful' => (int) $row['helpful'],
            ];
        }

        return $liked;
    }
}
