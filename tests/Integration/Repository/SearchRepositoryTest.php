<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

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
}
