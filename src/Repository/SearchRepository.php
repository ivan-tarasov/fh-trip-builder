<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use DateTimeImmutable;
use TripBuilder\CabinClass;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Live-verified against the schema: every column is non-nullable except
 * `return` (a DATE); `depart`/`return` come back `string`, not a `DateTime`;
 * `depart_span`/`return_span` are plain `tinyint` columns and stay `int`.
 *
 * @phpstan-type SearchRow array{
 *     hash: string, from_code: string, from_name: string,
 *     to_code: string, to_name: string,
 *     depart: string, depart_span: int, return: string|null, return_span: int,
 *     triptype: string, class: string, search_count: int, last_search: string,
 * }
 */
final readonly class SearchRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * The most-searched routes first (matches the legacy `orderBy('search_count')`
     * default DESC direction).
     *
     * @return list<SearchRow>
     */
    public function topSearches(int $limit): array
    {
        // Only trips that can still be taken. A row is identified partly by its
        // departure date, so a popular search ages into a dead one: the most
        // searched route on the home page was offering a date two days before
        // the earliest flight in the table, and returned nothing when clicked.
        // The card prints the date without a year, so it read as an upcoming
        // trip rather than a stale one.
        //
        // CURDATE(), now that `depart` is a DATE: the comparison is between two
        // dates rather than between two strings that happen to sort like them,
        // and the cutoff is the database's own day rather than PHP's.
        /** @var list<SearchRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Search->value
            . ' WHERE depart >= CURDATE()'
            . ' ORDER BY search_count DESC LIMIT ' . $limit,
        );

        return $rows;
    }

    /**
     * @return SearchRow|null
     */
    public function findByHash(string $hash): ?array
    {
        /** @var SearchRow|null $row */
        $row = $this->connection->fetchOne(
            'SELECT * FROM ' . Table::Search->value . ' WHERE hash = ? LIMIT 1',
            [$hash],
        );

        return $row;
    }

    /**
     * The hash identifying one search: the route, the dates, the trip type and
     * the cabin. Two searches differing in any of those are different rows,
     * which is what lets a hash link resolve back to the search that made it.
     *
     * Economy is left out of the digest on purpose. It is the default cabin, so
     * folding it in would change the hash of every search already recorded --
     * orphaning those rows and restarting the counts the homepage ranks by.
     * Any other cabin is appended, so it gets a row of its own.
     */
    public static function hashFor(
        string $fromCode,
        string $toCode,
        string $depart,
        ?string $return,
        string $triptype,
        CabinClass $cabin,
        int $departSpan = 1,
        int $returnSpan = 1,
    ): string {
        $identity = sprintf(
            '%s:%s:%s:%s:%s',
            $fromCode,
            $toCode,
            $depart,
            $return,
            $triptype,
        );

        if ($cabin !== CabinClass::Economy) {
            $identity .= ':' . $cabin->value;
        }

        // Appended only when there is a window, for the same reason Economy is
        // left out: folding a default into the digest would change the hash of
        // every search already recorded and orphan its count.
        if ($departSpan > 1 || $returnSpan > 1) {
            $identity .= sprintf(':%d:%d', $departSpan, $returnSpan);
        }

        return md5($identity);
    }

    /**
     * Record a search: insert it, or bump its count + last_search on repeat.
     * (`return` and `class` are reserved words, hence the backticks.)
     */
    public function record(
        string $hash,
        string $fromCode,
        string $fromName,
        string $toCode,
        string $toName,
        string $depart,
        ?string $return,
        string $triptype,
        CabinClass $cabin,
        int $departSpan = 1,
        int $returnSpan = 1,
    ): void {
        // Read before the write: this is what "have I already counted this
        // hash today" has to mean, and it is decided before the row's own
        // `daily_counted_on` moves to today below (G7.1, #332).
        $countedToday = $this->countedToday($hash);

        $this->connection->execute(
            'INSERT INTO ' . Table::Search->value
            . ' (hash, from_code, from_name, to_code, to_name, depart, depart_span,'
            . ' `return`, return_span, triptype, `class`, daily_counted_on)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
            . ' ON DUPLICATE KEY UPDATE search_count = search_count + 1, last_search = NOW(), daily_counted_on = CURDATE()',
            [$hash, $fromCode, $fromName, $toCode, $toName, $depart, $departSpan, $return, $returnSpan, $triptype, $cabin->value],
        );

        if (!$countedToday) {
            $this->bumpDailyCount();
        }
    }

    /**
     * Whether this route's hash has already bumped today's row in
     * `search_daily_counts` -- a route searched ten times in one day (nine
     * of them a filter re-applying the same search, not a new one) should
     * count as one day's search, not ten.
     */
    private function countedToday(string $hash): bool
    {
        /** @var string|null $countedOn */
        $countedOn = $this->connection->fetchValue(
            'SELECT daily_counted_on FROM ' . Table::Search->value . ' WHERE hash = ?',
            [$hash],
        );

        return $countedOn === date('Y-m-d');
    }

    /**
     * Today's row in the day-by-day count `search` itself cannot give --
     * every call here, not just a new route, since the search-to-book ratio
     * asks how many searches happened, not how many routes exist (G7.1,
     * #332).
     */
    private function bumpDailyCount(): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::SearchDailyCounts->value . ' (search_date, count) VALUES (CURDATE(), 1)'
            . ' ON DUPLICATE KEY UPDATE count = count + 1',
        );
    }

    /**
     * Every day in `[$from, $to)` with its search count, zero for a day
     * nothing recorded -- the same `date => value` shape
     * {@see CurrencyRateRepository::history()} already hands a sparkline,
     * so a chart reads a continuous run of days rather than sparse events.
     *
     * @return array<string, int>
     */
    public function dailyCounts(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{search_date: string, count: int}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT search_date, count FROM ' . Table::SearchDailyCounts->value
            . ' WHERE search_date >= ? AND search_date < ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')],
        );

        $byDate = [];

        foreach ($rows as $row) {
            $byDate[$row['search_date']] = (int) $row['count'];
        }

        $series = [];

        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $series[$date] = $byDate[$date] ?? 0;
        }

        return $series;
    }

    /** Every search in `[$from, $to)`, for the ratio's own numerator. */
    public function total(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        /** @var string $sum */
        $sum = $this->connection->fetchValue(
            'SELECT COALESCE(SUM(count), 0) FROM ' . Table::SearchDailyCounts->value
            . ' WHERE search_date >= ? AND search_date < ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')],
        );

        return (int) $sum;
    }
}
