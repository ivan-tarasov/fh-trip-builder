<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\AdminEvent;
use TripBuilder\AdminEventResource;
use TripBuilder\Repository\AdminEventRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * What an operator has changed, outside the booking and settings logs
 * (G5.1, #316).
 */
final class AdminEventLogTest extends IntegrationTestCase
{
    private const string RESOURCE_ID = 'zz-audit-log-test';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM admin_events WHERE resource_id = ?', [self::RESOURCE_ID]);
    }

    private function events(): AdminEventRepository
    {
        return new AdminEventRepository($this->connection());
    }

    public function testRecentReadsInTheOrderThingsHappened(): void
    {
        $events = $this->events();

        $events->record(AdminEventResource::Article, self::RESOURCE_ID, AdminEvent::Edited, 'First save');
        $events->record(AdminEventResource::Article, self::RESOURCE_ID, AdminEvent::Edited, 'Second save');

        $ours = array_values(array_filter(
            $events->recent(50),
            static fn(array $event): bool => $event['resource_id'] === self::RESOURCE_ID,
        ));

        self::assertCount(2, $ours);
        self::assertSame('Second save', $ours[0]['note'], 'newest first');
        self::assertSame('First save', $ours[1]['note']);
    }

    public function testARecordedEventReadsBackItsOwnShape(): void
    {
        $this->events()->record(AdminEventResource::Subscriber, self::RESOURCE_ID, AdminEvent::Removed, 'zz-audit@example.com');

        $found = self::mostRecent($this->events());

        self::assertNotNull($found);
        self::assertSame(AdminEventResource::Subscriber, $found['resource']);
        self::assertSame(AdminEvent::Removed, $found['event']);
        self::assertSame('zz-audit@example.com', $found['note']);
    }

    /**
     * A resource or event this version does not know still reads, as the raw
     * word rather than a fatal -- the same reasoning `BookingEventRepository`
     * gives for a row an older version wrote.
     */
    public function testAnUnknownResourceOrEventDegradesToItsRawWord(): void
    {
        $this->connection()->execute(
            'INSERT INTO admin_events (resource, resource_id, event, note, at) VALUES (?, ?, ?, ?, NOW())',
            ['not-a-real-resource', self::RESOURCE_ID, 'not-a-real-event', ''],
        );

        $found = self::mostRecent($this->events());

        self::assertNotNull($found);
        self::assertNull($found['resource']);
        self::assertSame('not-a-real-resource', $found['resource_raw']);
        self::assertNull($found['event']);
        self::assertSame('not-a-real-event', $found['event_raw']);
    }

    /** @return array{resource: ?AdminEventResource, resource_raw: string, resource_id: string, event: ?AdminEvent, event_raw: string, note: string, at: string}|null */
    private static function mostRecent(AdminEventRepository $events): ?array
    {
        foreach ($events->recent(50) as $event) {
            if ($event['resource_id'] === self::RESOURCE_ID) {
                return $event;
            }
        }

        return null;
    }
}
