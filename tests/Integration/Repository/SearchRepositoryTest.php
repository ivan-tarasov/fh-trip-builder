<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

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
            self::assertSame(2, (int) $second['search_count']);
        } finally {
            $connection->execute('DELETE FROM search WHERE hash = ?', [$hash]);
        }
    }

    public function testFindByHashReturnsNullWhenMissing(): void
    {
        self::assertNull((new SearchRepository($this->connection()))->findByHash('nope-' . uniqid()));
    }
}
