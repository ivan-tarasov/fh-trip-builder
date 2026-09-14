<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The answer to one candidate query, kept until it is old.
 *
 * The candidate query is the whole cost of a search — 76 to 200ms measured,
 * against about 4ms for everything else in the path put together — and it is
 * the join that is expensive rather than the answer: a route returning eight
 * itineraries still took 131ms to find them. So this stores very little and
 * saves a great deal (E31, #219).
 *
 * Every "show more" re-ran that query and sliced a different window, which made
 * paging cost more rather than less: five slices were five searches. Filters
 * and the party are applied to the candidates afterwards in PHP, so a filter
 * change reads this too.
 *
 * The key carries the highest flight id, so writing flights is what makes an
 * answer old rather than the clock. See `generation()`.
 *
 * @phpstan-type CandidateCacheRow array{candidates: string}
 */
final readonly class SearchCandidateRepository
{
    /**
     * How long an answer may be reused.
     *
     * Not the freshness rule -- `generation()` is, and it moves the moment new
     * flights are written. This only decides how long a row is worth keeping,
     * and it is short because the value is all in requests that follow one
     * another: a page, its filters and its "show more" happen within a minute
     * or two of each other, and nothing reads a row after that.
     */
    public const int KEEP_MINUTES = 30;

    public function __construct(private Connection $connection) {}

    /**
     * The key a statement and its parameters answer to, under the flights that
     * are in the table right now.
     *
     * Derived from the query rather than from the search's own fields, so there
     * is no second definition of "what makes two searches different" to keep in
     * step with the first. Change the sort, the cabin, the span, the date or
     * the SQL itself and the key changes with it.
     *
     * @param list<mixed> $params
     */
    public function keyFor(string $sql, array $params): string
    {
        return hash('sha256', $this->generation() . '|' . $sql . '|' . serialize($params));
    }

    /**
     * The stored answer, or null when there is not a fresh one.
     *
     * A row that cannot be read back -- written by an older shape, or truncated
     * -- is treated as a miss rather than an error. This is a cache: the worst
     * a bad row may do is cost the query it was meant to save.
     *
     * @return list<array<string, mixed>>|null
     */
    public function get(string $key): ?array
    {
        /** @var CandidateCacheRow|null $row */
        $row = $this->connection->fetchOne(
            'SELECT candidates FROM ' . Table::SearchCandidates->value
            . ' WHERE id = ? AND built_at >= ?',
            [$key, $this->staleBefore()],
        );

        if ($row === null) {
            return null;
        }

        // Silenced because both of these warn on input they cannot make sense
        // of, and `failOnWarning` would turn a bad row into a broken test run.
        // A bad row is not an error here; it is a miss.
        $raw = @gzuncompress($row['candidates']);

        if ($raw === false) {
            return null;
        }

        /** @var list<array<string, mixed>>|false $candidates */
        $candidates = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($candidates) ? array_values($candidates) : null;
    }

    /**
     * Keep an answer, replacing whatever was under that key.
     *
     * Failure is swallowed for the reason a bad row is: a cache that cannot be
     * written should slow the site down, not break it. Two requests for the
     * same search may both compute and both write, and the second simply wins
     * — they computed the same thing.
     *
     * @param list<array<string, mixed>> $candidates
     */
    public function put(string $key, array $candidates): void
    {
        try {
            $this->connection->execute(
                'INSERT INTO ' . Table::SearchCandidates->value . ' (id, candidates, built_at)'
                . ' VALUES (?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE candidates = VALUES(candidates), built_at = VALUES(built_at)',
                [
                    $key,
                    gzcompress(serialize($candidates), 6),
                    new DateTimeImmutable()->format('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable) {
            // Nothing to do about it, and nothing worth failing a search for.
        }
    }

    /** Answers older than the window, which nothing will read again. */
    public function forgetStale(): int
    {
        return $this->connection->execute(
            'DELETE FROM ' . Table::SearchCandidates->value . ' WHERE built_at < ?',
            [$this->staleBefore()],
        );
    }

    /** How many are still worth reading, for `db:prune` to report. */
    public function countStale(): int
    {
        return (int) $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::SearchCandidates->value . ' WHERE built_at < ?',
            [$this->staleBefore()],
        );
    }

    /**
     * The highest flight id, standing in for "which flights there are".
     *
     * It is what makes this a cache and not a delay. The generator writes the
     * next night's flights with ids above every id it has written before, so
     * the moment it finishes, every key moves and nobody is answered out of
     * yesterday. Measured at 0.3ms against the 196ms the cached statement
     * costs, which is a third of one percent; `COUNT(*)` would have said the
     * same thing for 33ms and was not worth it.
     *
     * Removing flights does not move it. That is the one gap and it is small:
     * the sweep runs ten minutes after the generator, and an itinerary naming a
     * flight that has gone loses its card in `assembleItinerary()` rather than
     * showing a flight nobody can take.
     */
    private function generation(): int
    {
        return (int) $this->connection->fetchValue('SELECT MAX(id) FROM ' . Table::Flights->value);
    }

    private function staleBefore(): string
    {
        return new DateTimeImmutable('-' . self::KEEP_MINUTES . ' minutes')->format('Y-m-d H:i:s');
    }
}
