<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\AirportRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

final class AirportRepositoryTest extends IntegrationTestCase
{
    private const EXPECTED_COLUMNS = [
        'code', 'title', 'country', 'city_code', 'city',
        'timezone', 'timezone_name', 'latitude', 'longitude', 'altitude',
    ];

    public function testEnabledReturnsRowsWithTheCountryJoinColumns(): void
    {
        $airports = $this->repository()->enabled(false);

        self::assertNotEmpty($airports);
        self::assertSame(self::EXPECTED_COLUMNS, array_keys($airports[0]));
    }

    public function testEnabledMajorOnlyIsASubsetOfAllEnabled(): void
    {
        $all = $this->repository()->enabled(false);
        $major = $this->repository()->enabled(true);

        self::assertLessThanOrEqual(count($all), count($major));
        self::assertNotEmpty($major);
    }

    public function testEnabledIsOrderedByTitle(): void
    {
        $titles = array_column($this->repository()->enabled(true), 'title');
        $canonical = array_column(
            $this->connection()->fetchAll(
                'SELECT a.title FROM airports a WHERE a.enabled = 1 AND is_major = 1 ORDER BY a.title ASC',
            ),
            'title',
        );

        self::assertSame($canonical, $titles);
    }

    public function testEveryPlaceOfferedIsOneTheNetworkActuallyServes(): void
    {
        // The form ships this list instead of asking the server per keystroke,
        // so anything in it is a search someone can run. Flights are generated
        // only between major airports, and offering any other would lead to a
        // guaranteed empty result.
        $places = $this->repository()->pickable();

        self::assertNotEmpty($places);

        $served = array_column(
            $this->connection()->fetchAll(
                'SELECT code FROM airports WHERE enabled = 1 AND is_major = 1'
                . ' UNION SELECT DISTINCT city_code FROM airports WHERE enabled = 1 AND is_major = 1',
            ),
            'code',
        );

        self::assertEmpty(array_diff(array_column($places, 'code'), $served));
    }

    public function testNoTwoPlacesOfferTheSameCode(): void
    {
        // A city with one airport shares that airport's code, and in a
        // multi-airport city one airport often carries the city's code too.
        // Either way two rows with one value is a list that looks like it
        // offers a choice it cannot make -- resolveAirportCodes() expands both
        // to exactly the same set.
        $codes = array_column($this->repository()->pickable(), 'code');

        self::assertSame(count($codes), count(array_unique($codes)));
    }

    public function testACityLeadsTheAirportsItExpandsTo(): void
    {
        // Picking the city searches all of them, so it belongs with them rather
        // than somewhere else alphabetically.
        $places = $this->repository()->pickable();
        $cities = array_values(array_filter($places, static fn(array $p): bool => (int) $p['is_city'] === 1));

        self::assertNotEmpty($cities, 'no multi-airport city in the data');

        foreach ($cities as $city) {
            $at = array_search($city['code'], array_column($places, 'code'), true);

            self::assertIsInt($at);
            self::assertArrayHasKey($at + 1, $places, $city['label'] . ' leads nothing');
            self::assertSame(
                0,
                (int) $places[$at + 1]['is_city'],
                $city['label'] . ' is followed by another city rather than its airports',
            );
        }
    }

    private function repository(): AirportRepository
    {
        return new AirportRepository($this->connection());
    }
}
