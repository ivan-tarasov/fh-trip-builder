<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use DateTimeImmutable;
use TripBuilder\CabinClass;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * @phpstan-type RouteWatchRow array{
 *     id: int, email: string, threshold: float, notified_price: ?float,
 * }
 * @phpstan-type AdminRouteWatchRow array{
 *     id: int, email: string, from_code: string, to_code: string, cabin: string,
 *     threshold: float, notified_price: ?float, created: string,
 * }
 * @phpstan-type TopRouteRow array{from: string, to: string, cabin: string, count: int, bar: int}
 */
final readonly class RouteWatchRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Register a watch, or update one already there for this exact address
     * and route.
     *
     * A changed threshold clears `notified_price`: a visitor who lowers what
     * they are willing to pay should be evaluated fresh against it, not
     * silenced by an alert sent for the old, higher one.
     */
    public function subscribe(string $email, string $from, string $to, CabinClass $cabin, float $threshold): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::RouteWatches->value
            . ' (email, from_code, to_code, cabin, threshold) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE threshold = ?, notified_price = NULL',
            [$email, $from, $to, $cabin->value, $threshold, $threshold],
        );
    }

    /**
     * Every distinct route being watched -- what `alerts:check` groups by,
     * so a busy route with a hundred watches costs one `cheapest()` read
     * rather than a hundred.
     *
     * @return list<array{from_code: string, to_code: string, cabin: string}>
     */
    public function routes(): array
    {
        /** @var list<array{from_code: string, to_code: string, cabin: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT from_code, to_code, cabin FROM ' . Table::RouteWatches->value,
        );

        return $rows;
    }

    /**
     * Every watch on one route.
     *
     * @return list<RouteWatchRow>
     */
    public function forRoute(string $from, string $to, CabinClass $cabin): array
    {
        /** @var list<array{id: int|string, email: string, threshold: float|string, notified_price: float|string|null}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, email, threshold, notified_price FROM ' . Table::RouteWatches->value
            . ' WHERE from_code = ? AND to_code = ? AND cabin = ?',
            [$from, $to, $cabin->value],
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'threshold' => (float) $row['threshold'],
            'notified_price' => $row['notified_price'] === null ? null : (float) $row['notified_price'],
        ], $rows);
    }

    /** Record the price a watch was just alerted at. */
    public function markNotified(int $id, float $price): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::RouteWatches->value . ' SET notified_price = ? WHERE id = ?',
            [$price, $id],
        );
    }

    /** The price has risen back above threshold -- the next dip should alert fresh. */
    public function clearNotified(int $id): void
    {
        $this->connection->execute(
            'UPDATE ' . Table::RouteWatches->value . ' SET notified_price = NULL WHERE id = ?',
            [$id],
        );
    }

    /** Every watch there is, active right now -- G21 (#385)'s own headline number. */
    public function count(): int
    {
        /** @var int $count */
        $count = $this->connection->fetchValue('SELECT COUNT(*) FROM ' . Table::RouteWatches->value);

        return $count;
    }

    /** How many distinct routes (from, to, cabin) carry at least one watch. */
    public function distinctRouteCount(): int
    {
        /** @var int $count */
        $count = $this->connection->fetchValue(
            'SELECT COUNT(*) FROM (SELECT 1 FROM ' . Table::RouteWatches->value
            . ' GROUP BY from_code, to_code, cabin) t',
        );

        return $count;
    }

    /**
     * Watches sitting at or under their threshold right now -- the ones
     * `alerts:check`'s next tick would already have a reason to email.
     */
    public function triggeredCount(): int
    {
        /** @var int $count */
        $count = $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::RouteWatches->value . ' WHERE notified_price IS NOT NULL',
        );

        return $count;
    }

    /**
     * Every day in `[$from, $to)` with how many watches were registered,
     * zero for a day nothing was -- the same continuous `date => value` shape
     * {@see SearchRepository::dailyCounts()} already
     * gives its own trend, so a chart reads a run of days rather than sparse
     * events.
     *
     * @return array<string, int>
     */
    public function dailyCreatedCounts(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{d: string, c: int}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT DATE(created) AS d, COUNT(*) AS c FROM ' . Table::RouteWatches->value
            . ' WHERE created >= ? AND created < ? GROUP BY DATE(created)',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );

        return self::series($rows, $from, $to);
    }

    /**
     * Every real send, one per qualifying watch -- called once per
     * `Check::execute()` send, never once per tick, so a route with three
     * watches that all qualify counts as three (G21, #385).
     */
    public function recordAlertSent(): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::RouteWatchAlertsSent->value . ' (sent_date, count) VALUES (CURDATE(), 1)'
            . ' ON DUPLICATE KEY UPDATE count = count + 1',
        );
    }

    /**
     * Every day in `[$from, $to)` with how many alerts went out, zero for a
     * day nothing sent.
     *
     * @return array<string, int>
     */
    public function dailySentCounts(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{sent_date: string, count: int}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT sent_date, count FROM ' . Table::RouteWatchAlertsSent->value
            . ' WHERE sent_date >= ? AND sent_date < ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')],
        );

        $byDate = [];

        foreach ($rows as $row) {
            $byDate[$row['sent_date']] = (int) $row['count'];
        }

        return self::fillSeries($byDate, $from, $to);
    }

    /** Every alert sent in `[$from, $to)`. */
    public function totalSent(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        /** @var string $sum */
        $sum = $this->connection->fetchValue(
            'SELECT COALESCE(SUM(count), 0) FROM ' . Table::RouteWatchAlertsSent->value
            . ' WHERE sent_date >= ? AND sent_date < ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')],
        );

        return (int) $sum;
    }

    /**
     * The busiest routes, by how many watches are on each -- the same
     * `bar` (percent of the top row) {@see DashboardRepository::topSearches()}
     * already hands its own ranked list.
     *
     * @return list<TopRouteRow>
     */
    public function topRoutes(int $limit): array
    {
        /** @var list<array{from_code: string, to_code: string, cabin: string, watches: int}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT from_code, to_code, cabin, COUNT(*) AS watches FROM ' . Table::RouteWatches->value
            . ' GROUP BY from_code, to_code, cabin'
            . ' ORDER BY watches DESC LIMIT ' . max(1, $limit),
        );

        $top = $rows === [] ? 0 : (int) $rows[0]['watches'];

        return array_map(static fn(array $row): array => [
            'from' => (string) $row['from_code'],
            'to' => (string) $row['to_code'],
            'cabin' => (string) $row['cabin'],
            'count' => (int) $row['watches'],
            'bar' => $top > 0 ? (int) round((int) $row['watches'] / $top * 100) : 0,
        ], $rows);
    }

    /**
     * A page of watches for the admin list, newest first -- optionally
     * narrowed to one address, the same reasoning searching by email lets an
     * operator see one subscriber's own rows together without the table
     * itself being grouped by subscriber (G21, #385).
     *
     * @return list<AdminRouteWatchRow>
     */
    public function paginated(int $limit, int $offset, ?string $term = null): array
    {
        $where = $term !== null && $term !== '' ? ' WHERE email LIKE ?' : '';
        $params = $term !== null && $term !== '' ? [self::like($term)] : [];
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<array{id: int|string, email: string, from_code: string, to_code: string, cabin: string, threshold: float|string, notified_price: float|string|null, created: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, email, from_code, to_code, cabin, threshold, notified_price, created FROM '
            . Table::RouteWatches->value . $where . ' ORDER BY created DESC, id DESC LIMIT ? OFFSET ?',
            $params,
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'from_code' => (string) $row['from_code'],
            'to_code' => (string) $row['to_code'],
            'cabin' => (string) $row['cabin'],
            'threshold' => (float) $row['threshold'],
            'notified_price' => $row['notified_price'] === null ? null : (float) $row['notified_price'],
            'created' => (string) $row['created'],
        ], $rows);
    }

    /** How many rows {@see paginated()} would page over, for the same term. */
    public function countMatching(?string $term = null): int
    {
        $where = $term !== null && $term !== '' ? ' WHERE email LIKE ?' : '';
        $params = $term !== null && $term !== '' ? [self::like($term)] : [];

        /** @var int $count */
        $count = $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::RouteWatches->value . $where,
            $params,
        );

        return $count;
    }

    /**
     * A short description of each watch, before it is removed -- the admin
     * log's own note, the same reasoning {@see SubscriberRepository::emailsFor()}
     * gives: a deleted row cannot answer for itself afterwards.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function descriptorsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array{id: int, email: string, from_code: string, to_code: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, email, from_code, to_code FROM ' . Table::RouteWatches->value
            . ' WHERE id IN (' . self::placeholders($ids) . ')',
            $ids,
        );

        $descriptors = [];

        foreach ($rows as $row) {
            $descriptors[$row['id']] = $row['email'] . ' -- ' . $row['from_code'] . ' to ' . $row['to_code'];
        }

        return $descriptors;
    }

    /** Take one watch off the list. True when a row was actually removed. */
    public function remove(int $id): bool
    {
        return $this->connection->execute(
            'DELETE FROM ' . Table::RouteWatches->value . ' WHERE id = ?',
            [$id],
        ) > 0;
    }

    /**
     * Take zero or more watches off the list in one query -- the bulk
     * counterpart to {@see remove()}.
     *
     * @param list<int> $ids
     */
    public function removeMany(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->connection->execute(
            'DELETE FROM ' . Table::RouteWatches->value . ' WHERE id IN (' . self::placeholders($ids) . ')',
            $ids,
        );
    }

    /**
     * @param list<array{d: string, c: int}> $rows
     * @return array<string, int>
     */
    private static function series(array $rows, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $byDate = [];

        foreach ($rows as $row) {
            $byDate[$row['d']] = (int) $row['c'];
        }

        return self::fillSeries($byDate, $from, $to);
    }

    /**
     * @param array<string, int> $byDate
     * @return array<string, int>
     */
    private static function fillSeries(array $byDate, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $series = [];

        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $series[$date] = $byDate[$date] ?? 0;
        }

        return $series;
    }

    private static function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }

    /** @param list<int> $ids */
    private static function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }
}
