<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use Throwable;
use TripBuilder\AdminEvent;
use TripBuilder\AdminEventResource;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * What an operator has changed, outside the two logs that already cover
 * bookings and settings (G5.1, #316).
 *
 * Append-only, and writing never fails the caller's real write -- the same
 * reasoning {@see BookingEventRepository} gives for both.
 *
 * @phpstan-type AdminEventRow array{resource: string, resource_id: string, event: string, note: ?string, at: string}
 */
final readonly class AdminEventRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * @param string $note the one detail the kind cannot carry -- an
     *     article's title, or the address that was removed.
     */
    public function record(AdminEventResource $resource, string $resourceId, AdminEvent $event, string $note = ''): void
    {
        try {
            $this->connection->execute(
                'INSERT INTO ' . Table::AdminEvents->value
                . ' (resource, resource_id, event, note, at) VALUES (?, ?, ?, ?, NOW())',
                [$resource->value, $resourceId, $event->value, mb_substr($note, 0, 190)],
            );
        } catch (Throwable) {
            // Nothing to do about it, and nothing worth failing a save for.
        }
    }

    /**
     * The most recent events across every resource, newest first.
     *
     * `resource`/`event` come back as the case where this version knows it
     * and as the raw word where it does not, the same reasoning
     * {@see BookingEventRepository::forBooking()} gives.
     *
     * @return list<array{resource: ?AdminEventResource, resource_raw: string, resource_id: string, event: ?AdminEvent, event_raw: string, note: string, at: string}>
     */
    public function recent(int $limit): array
    {
        /** @var list<AdminEventRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT resource, resource_id, event, note, at FROM ' . Table::AdminEvents->value
            . ' ORDER BY at DESC, id DESC LIMIT ' . max(1, $limit),
        );

        return array_map(static fn(array $row): array => [
            'resource' => AdminEventResource::tryFrom((string) $row['resource']),
            'resource_raw' => (string) $row['resource'],
            'resource_id' => (string) $row['resource_id'],
            'event' => AdminEvent::tryFrom((string) $row['event']),
            'event_raw' => (string) $row['event'],
            'note' => (string) ($row['note'] ?? ''),
            'at' => (string) $row['at'],
        ], $rows);
    }
}
