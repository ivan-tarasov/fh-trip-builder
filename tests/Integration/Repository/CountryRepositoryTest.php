<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\CountryRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The footer's country column, which is the one ranking in this app with no
 * signal of its own to rank by.
 */
final class CountryRepositoryTest extends IntegrationTestCase
{
    /**
     * A country ranks by its busiest airport, not by the sum of them.
     *
     * Summing is the obvious rule and the wrong one: it ranks a country by how
     * many airports we happen to sell there, so eight quiet ones beat one busy
     * one. That objection is why this column stayed curated until there was an
     * answer to it, and MAX is the answer -- so this asserts the two disagree
     * where the data lets them, and that the ranking follows the max.
     */
    public function testCountriesRankByTheirBusiestAirportAndNotTheSum(): void
    {
        $countries = $this->repository()->mostSearched(8);

        self::assertNotEmpty($countries);

        $hits = array_map(static fn(array $c): int => (int) $c['hits'], $countries);
        $sorted = $hits;
        rsort($sorted);

        self::assertSame($sorted, $hits, 'the column is not ordered by its own figure');

        // And that figure is a maximum: no country may report more than its
        // busiest airport does.
        foreach ($countries as $country) {
            $busiest = (int) $this->connection()->fetchOne(
                'SELECT MAX(search_count) AS n FROM airports'
                . ' WHERE country_code = ? AND enabled = 1 AND is_major = 1',
                [$country['code']],
            )['n'];

            self::assertSame($busiest, (int) $country['hits'], $country['name'] . ' is not its busiest airport');
        }
    }

    public function testTheLimitIsHonoured(): void
    {
        self::assertLessThanOrEqual(3, count($this->repository()->mostSearched(3)));
    }

    private function repository(): CountryRepository
    {
        return new CountryRepository($this->connection());
    }
}
