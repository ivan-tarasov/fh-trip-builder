<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * @phpstan-type SubscriberRow array{id: int, email: string, subscribed_at: string}
 */
final readonly class SubscriberRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Put an address on the list.
     *
     * True when it was added, false when it was already there. Not an error
     * either way: somebody who subscribes twice has asked for the same thing
     * twice and should be told the same thing twice.
     *
     * INSERT IGNORE rather than a SELECT and then an INSERT. Two people
     * submitting the same address at the same moment both pass the check and
     * both insert, and the unique index is the only place that race can
     * actually be settled -- so it settles it, and a duplicate comes back as
     * zero rows affected instead of an exception.
     *
     * Addresses are stored lowercased. The local part of an address is
     * case-sensitive by the letter of the spec and by nobody in practice, and a
     * list that holds Someone@example.com and someone@example.com sends the
     * same person two of everything.
     */
    public function add(string $email): bool
    {
        return $this->connection->execute(
            'INSERT IGNORE INTO ' . Table::Subscribers->value . ' (email, subscribed_at) VALUES (?, NOW())',
            [mb_strtolower($email)],
        ) > 0;
    }

    /**
     * Every address on the list, newest first.
     *
     * `id DESC` breaks a tie `subscribed_at` cannot: the column is
     * second-precision, so two addresses added inside one second would
     * otherwise come back in whatever order the storage engine felt like.
     *
     * @return list<SubscriberRow>
     */
    public function all(int $limit, int $offset = 0): array
    {
        /** @var list<SubscriberRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, email, subscribed_at FROM ' . Table::Subscribers->value
            . ' ORDER BY subscribed_at DESC, id DESC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );

        return $rows;
    }

    /**
     * Every address on the list, newest first, for an export.
     *
     * `all()` without the page, same reasoning as
     * {@see BookingRepository::exportAll()} (G3.5, #308).
     *
     * @return list<SubscriberRow>
     */
    public function exportAll(): array
    {
        /** @var list<SubscriberRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, email, subscribed_at FROM ' . Table::Subscribers->value
            . ' ORDER BY subscribed_at DESC, id DESC',
        );

        return $rows;
    }

    public function countAll(): int
    {
        /** @var int $count */
        $count = $this->connection->fetchValue('SELECT COUNT(*) FROM ' . Table::Subscribers->value);

        return $count;
    }

    /**
     * Take one address off the list.
     *
     * True when a row was actually removed, so a caller can tell a stale id
     * (already removed, or never one) from one that just left.
     */
    public function remove(int $id): bool
    {
        return $this->connection->execute(
            'DELETE FROM ' . Table::Subscribers->value . ' WHERE id = ?',
            [$id],
        ) > 0;
    }
}
