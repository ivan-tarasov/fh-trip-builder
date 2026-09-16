<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use DateTimeImmutable;
use TripBuilder\CabinClass;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class SearchRepositoryTest extends IntegrationTestCase
{
    public function testTopSearchesRespectsLimitAndDescOrder(): void
    {
        $repo = new SearchRepository($this->connection());

        $top = $repo->topSearches(5);
        self::assertLessThanOrEqual(5, count($top));

        $counts = array_map('intval', array_column($top, 'search_count'));
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts, 'search_count must be DESC');
    }

    public function testHashSeparatesCabinsButLeavesEconomyAlone(): void
    {
        $args = ['YUL', 'YYZ', '2026-09-15', '2026-09-22', 'roundtrip'];

        $economy = SearchRepository::hashFor(...[...$args, CabinClass::Economy]);
        $business = SearchRepository::hashFor(...[...$args, CabinClass::Business]);
        $first = SearchRepository::hashFor(...[...$args, CabinClass::First]);

        // Each cabin is its own row, so a hash link comes back in the cabin it
        // was made for rather than being overwritten by the next searcher.
        self::assertNotSame($economy, $business);
        self::assertNotSame($business, $first);

        // Economy keeps the digest it had before the cabin joined the identity,
        // so rows recorded then keep their hash and their accumulated count.
        self::assertSame(md5('YUL:YYZ:2026-09-15:2026-09-22:roundtrip'), $economy);
    }

    public function testRecordInsertsThenIncrementsOnDuplicate(): void
    {
        $connection = $this->connection();
        $repo = new SearchRepository($connection);
        $hash = 'test-' . uniqid();

        try {
            $repo->record($hash, 'YUL', 'Montreal', 'YYZ', 'Toronto', '2026-09-15', '2026-09-22', 'roundtrip', CabinClass::Economy);
            $first = $repo->findByHash($hash);
            self::assertNotNull($first);
            self::assertSame('YUL', $first['from_code']);
            self::assertSame(1, (int) $first['search_count']);
            self::assertSame('economy', $first['class']);

            // Same hash again → count increments, no duplicate row.
            $repo->record($hash, 'YUL', 'Montreal', 'YYZ', 'Toronto', '2026-09-15', '2026-09-22', 'roundtrip', CabinClass::Economy);
            $second = $repo->findByHash($hash);

            self::assertNotNull($second, 'the hash was just recorded a second time');
            self::assertSame(2, (int) $second['search_count']);
        } finally {
            $connection->execute('DELETE FROM search WHERE hash = ?', [$hash]);

            // `record()` also bumps today's row in `search_daily_counts`
            // (G7.1, #332) -- a shared, date-keyed counter with no sentinel
            // of its own to delete, so this undoes exactly the two calls
            // above by subtracting rather than deleting the day's row,
            // which could belong to other activity too.
            $connection->execute(
                'UPDATE search_daily_counts SET count = count - 2 WHERE search_date = CURDATE()',
            );
        }
    }

    public function testFindByHashReturnsNullWhenMissing(): void
    {
        self::assertNull((new SearchRepository($this->connection()))->findByHash('nope-' . uniqid()));
    }

    /**
     * A pair of dates with a gap between them and nothing either side --
     * `search_date` is the primary key, so writing to it directly (rather
     * than through `record()`) is the only way to control which day a row
     * lands on without also touching `search` (G7.1, #332).
     */
    public function testDailyCountsFillsGapsAndTotalSumsTheWindow(): void
    {
        $connection = $this->connection();
        $repo = new SearchRepository($connection);

        $connection->execute(
            'INSERT INTO search_daily_counts (search_date, count) VALUES (?, ?), (?, ?)',
            ['2020-06-15', 3, '2020-06-17', 2],
        );

        try {
            $series = $repo->dailyCounts(new DateTimeImmutable('2020-06-14'), new DateTimeImmutable('2020-06-18'));

            self::assertSame([
                '2020-06-14' => 0,
                '2020-06-15' => 3,
                '2020-06-16' => 0,
                '2020-06-17' => 2,
            ], $series);

            self::assertSame(5, $repo->total(new DateTimeImmutable('2020-06-14'), new DateTimeImmutable('2020-06-18')));
        } finally {
            $connection->execute(
                'DELETE FROM search_daily_counts WHERE search_date IN (?, ?)',
                ['2020-06-15', '2020-06-17'],
            );
        }
    }

    public function testRecordBumpsTodaysDailyCount(): void
    {
        $connection = $this->connection();
        $repo = new SearchRepository($connection);
        $hash = 'test-' . uniqid();

        $today = new DateTimeImmutable('today');
        $before = $repo->total($today, $today->modify('+1 day'));

        try {
            $repo->record($hash, 'YUL', 'Montreal', 'YYZ', 'Toronto', '2026-09-15', '2026-09-22', 'roundtrip', CabinClass::Economy);

            self::assertSame($before + 1, $repo->total($today, $today->modify('+1 day')));
        } finally {
            $connection->execute('DELETE FROM search WHERE hash = ?', [$hash]);
            $connection->execute('UPDATE search_daily_counts SET count = count - 1 WHERE search_date = CURDATE()');
        }
    }
}
