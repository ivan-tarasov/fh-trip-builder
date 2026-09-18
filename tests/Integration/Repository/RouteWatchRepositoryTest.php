<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use DateTimeImmutable;
use TripBuilder\CabinClass;
use TripBuilder\Repository\RouteWatchRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * Watching a route for a price: registering, reading a route's own watches
 * back, and the notified-price bookkeeping `alerts:check` relies on.
 */
final class RouteWatchRepositoryTest extends IntegrationTestCase
{
    private const string SENTINEL = 'zzwatch-';
    private const string FROM = 'ZZW';
    private const string TO = 'ZZV';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute(
            'DELETE FROM route_watches WHERE email LIKE ?',
            [self::SENTINEL . '%'],
        );
    }

    private function watches(): RouteWatchRepository
    {
        return new RouteWatchRepository($this->connection());
    }

    private function email(string $suffix): string
    {
        return self::SENTINEL . $suffix . '@example.com';
    }

    public function testSubscribingTwiceUpdatesRatherThanDuplicates(): void
    {
        $email = $this->email('twice');
        $watches = $this->watches();

        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 500.0);
        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 400.0);

        $found = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy);

        self::assertCount(1, $found, 'a second subscribe should update, not add a row');
        self::assertSame(400.0, $found[0]['threshold']);
    }

    /**
     * A changed threshold clears whatever this watch was last notified at --
     * it should be judged fresh against the new number, not silenced by an
     * alert sent for the old one.
     */
    public function testChangingTheThresholdClearsNotifiedPrice(): void
    {
        $email = $this->email('reset');
        $watches = $this->watches();

        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 500.0);
        $id = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['id'];
        $watches->markNotified($id, 450.0);

        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 300.0);

        $found = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy);

        self::assertSame(300.0, $found[0]['threshold']);
        self::assertNull($found[0]['notified_price']);
    }

    public function testRoutesListsEachWatchedRouteOnce(): void
    {
        $watches = $this->watches();
        $watches->subscribe($this->email('a'), self::FROM, self::TO, CabinClass::Economy, 500.0);
        $watches->subscribe($this->email('b'), self::FROM, self::TO, CabinClass::Economy, 600.0);

        $routes = array_filter(
            $watches->routes(),
            static fn(array $r): bool => $r['from_code'] === self::FROM && $r['to_code'] === self::TO,
        );

        self::assertCount(1, $routes, 'two watches on the same route should be one route to check');
    }

    public function testMarkAndClearNotified(): void
    {
        $watches = $this->watches();
        $watches->subscribe($this->email('mark'), self::FROM, self::TO, CabinClass::Economy, 500.0);
        $id = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['id'];

        $watches->markNotified($id, 420.0);
        self::assertSame(420.0, $watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['notified_price']);

        $watches->clearNotified($id);
        self::assertNull($watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['notified_price']);
    }

    /**
     * The three admin-page snapshot numbers (G21, #385), checked as a delta
     * rather than an absolute value -- the table is shared with whatever
     * else is on it, the same reasoning `SearchRepositoryTest`'s own daily-
     * count tests give for not asserting an exact total.
     */
    public function testCountDistinctRouteCountAndTriggeredCountMoveByOne(): void
    {
        $watches = $this->watches();
        $beforeCount = $watches->count();
        $beforeRoutes = $watches->distinctRouteCount();
        $beforeTriggered = $watches->triggeredCount();

        $watches->subscribe($this->email('snapshot'), self::FROM, self::TO, CabinClass::Economy, 500.0);
        $id = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['id'];

        self::assertSame($beforeCount + 1, $watches->count());
        self::assertSame($beforeRoutes + 1, $watches->distinctRouteCount());
        self::assertSame($beforeTriggered, $watches->triggeredCount(), 'not triggered until notified');

        $watches->markNotified($id, 450.0);

        self::assertSame($beforeTriggered + 1, $watches->triggeredCount());
    }

    public function testDailyCreatedCountsFillsGapsForARoute(): void
    {
        $connection = $this->connection();
        $watches = $this->watches();
        $email = $this->email('created-series');

        $connection->execute(
            'INSERT INTO route_watches (email, from_code, to_code, cabin, threshold, created)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [$email, self::FROM, self::TO, CabinClass::Economy->value, 500.0, '2020-06-15 12:00:00'],
        );

        $series = $watches->dailyCreatedCounts(new DateTimeImmutable('2020-06-14'), new DateTimeImmutable('2020-06-17'));

        self::assertSame([
            '2020-06-14' => 0,
            '2020-06-15' => 1,
            '2020-06-16' => 0,
        ], $series);
    }

    public function testTopRoutesRanksByWatchCountAndBarsTheBusiestAtTheFull(): void
    {
        $watches = $this->watches();
        $watches->subscribe($this->email('top-a'), self::FROM, self::TO, CabinClass::Economy, 500.0);
        $watches->subscribe($this->email('top-b'), self::FROM, self::TO, CabinClass::Economy, 600.0);

        $mine = array_values(array_filter(
            $watches->topRoutes(50),
            static fn(array $row): bool => $row['from'] === self::FROM && $row['to'] === self::TO,
        ));

        self::assertCount(1, $mine, 'two watches on the same route is one ranked row');
        self::assertSame(2, $mine[0]['count']);
        self::assertSame(100, $mine[0]['bar'], 'the row with the most watches bars at the full width');
    }

    public function testPaginatedFiltersByAddressAndCountMatchingAgrees(): void
    {
        $watches = $this->watches();
        $email = $this->email('paginated');
        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 500.0);

        $found = $watches->paginated(10, 0, $email);

        self::assertCount(1, $found);
        self::assertSame($email, $found[0]['email']);
        self::assertSame(1, $watches->countMatching($email));

        self::assertSame(0, $watches->countMatching($this->email('not-there')));
    }

    public function testDescriptorsForNamesTheWatchBeforeItIsRemoved(): void
    {
        $watches = $this->watches();
        $email = $this->email('descriptor');
        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 500.0);
        $id = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['id'];

        $descriptors = $watches->descriptorsFor([$id]);

        self::assertSame($email . ' -- ' . self::FROM . ' to ' . self::TO, $descriptors[$id]);
    }

    public function testRemoveAndRemoveManyDeleteRows(): void
    {
        $watches = $this->watches();
        $watches->subscribe($this->email('remove-a'), self::FROM, self::TO, CabinClass::Economy, 500.0);
        $watches->subscribe($this->email('remove-b'), self::FROM, self::TO, CabinClass::Economy, 600.0);
        $watches->subscribe($this->email('remove-c'), self::FROM, self::TO, CabinClass::Economy, 700.0);

        $rows = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy);
        $ids = array_column($rows, 'id');

        self::assertTrue($watches->remove($ids[0]));
        self::assertFalse($watches->remove($ids[0]), 'already gone, so this one removes nothing');
        self::assertSame(2, $watches->removeMany([$ids[1], $ids[2]]));

        self::assertSame([], $watches->forRoute(self::FROM, self::TO, CabinClass::Economy));
    }

    /**
     * The one call `Check::execute()` makes per real send, the same shape
     * `SearchRepositoryTest::testRecordBumpsTodaysDailyCount()` already
     * verifies for `search_daily_counts` (G21, #385).
     */
    public function testRecordAlertSentBumpsTodaysCount(): void
    {
        $connection = $this->connection();
        $watches = $this->watches();
        $today = new DateTimeImmutable('today');
        $before = $watches->totalSent($today, $today->modify('+1 day'));

        try {
            $watches->recordAlertSent();

            self::assertSame($before + 1, $watches->totalSent($today, $today->modify('+1 day')));
        } finally {
            $connection->execute('UPDATE route_watch_alerts_sent SET count = count - 1 WHERE sent_date = CURDATE()');
        }
    }

    public function testDailySentCountsFillsGapsAndTotalSumsTheWindow(): void
    {
        $connection = $this->connection();
        $watches = $this->watches();

        $connection->execute(
            'INSERT INTO route_watch_alerts_sent (sent_date, count) VALUES (?, ?), (?, ?)',
            ['2020-06-15', 3, '2020-06-17', 2],
        );

        try {
            $series = $watches->dailySentCounts(new DateTimeImmutable('2020-06-14'), new DateTimeImmutable('2020-06-18'));

            self::assertSame([
                '2020-06-14' => 0,
                '2020-06-15' => 3,
                '2020-06-16' => 0,
                '2020-06-17' => 2,
            ], $series);

            self::assertSame(5, $watches->totalSent(new DateTimeImmutable('2020-06-14'), new DateTimeImmutable('2020-06-18')));
        } finally {
            $connection->execute(
                'DELETE FROM route_watch_alerts_sent WHERE sent_date IN (?, ?)',
                ['2020-06-15', '2020-06-17'],
            );
        }
    }
}
