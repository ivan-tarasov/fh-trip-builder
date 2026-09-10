<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Who found which article helpful.
 *
 * One row per reader per article, keyed on both, so the key is the guard: a
 * reader who votes twice corrects their first vote instead of adding to a
 * count. Everything read here is derived from those rows rather than kept as a
 * running total, which is what makes correcting a vote possible at all.
 *
 * `SUM(helpful)` and `COUNT(*)` both come back from PDO as strings, so both are
 * cast on the way out for the reason CurrencyRateRepository::latest() casts its
 * rate: the score they are handed to is typed.
 */
final readonly class ArticleVoteRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Record a vote, or replace the one this reader already cast.
     *
     * Upserted rather than inserted: the second thumbs-up from the same reader
     * is not a second vote, and a thumbs-down after a thumbs-up is a change of
     * mind rather than one of each. `VALUES(col)` rather than the row alias,
     * because MariaDB has no alias syntax and the other repositories here
     * settled that the same way.
     */
    public function record(string $slug, string $voter, bool $helpful): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::ArticleVotes->value
            . ' (slug, voter, helpful, voted_at) VALUES (?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE helpful = VALUES(helpful), voted_at = NOW()',
            [$slug, $voter, $helpful ? 1 : 0],
        );
    }

    /**
     * Drop every vote cast on one article.
     *
     * Called when the article itself goes. Leaving them behind would be worse
     * than untidy: this table is keyed on the slug, so a later article reusing
     * a retired one would inherit a tally cast about something else -- and the
     * footer column ranks on that tally, so the new article would arrive
     * already sorted by opinions of a page nobody can read.
     */
    public function delete(string $slug): void
    {
        $this->connection->execute(
            'DELETE FROM ' . Table::ArticleVotes->value . ' WHERE slug = ?',
            [$slug],
        );
    }

    /**
     * Votes and yeses for every article anybody has voted on.
     *
     * Articles nobody has voted on are absent rather than present with nought,
     * because this cannot know what the full set of articles is -- that is
     * ArticleRepository's, and the caller has it.
     *
     * @return array<string, array{votes: int, helpful: int}>
     */
    public function tally(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT slug, COUNT(*) AS votes, SUM(helpful) AS helpful'
            . ' FROM ' . Table::ArticleVotes->value
            . ' GROUP BY slug',
        );

        $tally = [];

        foreach ($rows as $row) {
            $tally[(string) $row['slug']] = [
                'votes' => (int) $row['votes'],
                'helpful' => (int) $row['helpful'],
            ];
        }

        return $tally;
    }

    /**
     * The same figures for one article.
     *
     * Always a pair, nought and nought where nobody has voted, so a caller
     * never has to decide what an absent article means.
     *
     * @return array{votes: int, helpful: int}
     */
    public function tallyFor(string $slug): array
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS votes, SUM(helpful) AS helpful'
            . ' FROM ' . Table::ArticleVotes->value
            . ' WHERE slug = ?',
            [$slug],
        );

        return [
            'votes' => (int) ($row['votes'] ?? 0),
            'helpful' => (int) ($row['helpful'] ?? 0),
        ];
    }

    /**
     * How this reader voted, or null if they have not.
     *
     * Three answers and not two: null is "no vote yet" and false is "voted, and
     * said no". Collapsing those would make the page offer the buttons again to
     * somebody who has already used them.
     */
    public function verdictOf(string $slug, string $voter): ?bool
    {
        $helpful = $this->connection->fetchValue(
            'SELECT helpful FROM ' . Table::ArticleVotes->value . ' WHERE slug = ? AND voter = ?',
            [$slug, $voter],
        );

        return $helpful === null ? null : (int) $helpful === 1;
    }
}
