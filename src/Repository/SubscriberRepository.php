<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

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
}
