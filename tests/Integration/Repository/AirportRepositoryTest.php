<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\CityRepository;
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
        //
        // This used to assert only that a city is followed by *a* non-city, and
        // that is why it passed while the list was wrong: New York was followed
        // by JFK, so the check was satisfied, while EWR sat in a group of its
        // own because its `city` column says "Newark". The property the list
        // actually needs is the whole set, contiguously -- what the city
        // expands to is what has to follow it, and nothing else.
        $places = $this->repository()->pickable();
        $cities = array_values(array_filter($places, static fn(array $p): bool => (int) $p['is_city'] === 1));

        self::assertNotEmpty($cities, 'no multi-airport city in the data');

        foreach ($cities as $city) {
            $at = array_search($city['code'], array_column($places, 'code'), true);

            self::assertIsInt($at);
            self::assertArrayHasKey($at + 1, $places, $city['label'] . ' leads nothing');

            // Walked while the rows still belong to *this* city, not merely
            // while they are airports. A single-airport city has no city row
            // of its own, so its airport is a bare row -- and a walk that
            // stopped only at the next city row swept Barcelona's BCN into
            // Bangkok's group, which is a bug in the reading and not in the
            // list. `city` is the group's name for every row in it.
            $following = [];

            for (
                $i = $at + 1;
                isset($places[$i]) && (string) $places[$i]['city'] === (string) $city['label'];
                $i++
            ) {
                if ((int) $places[$i]['is_city'] === 0) {
                    $following[] = (string) $places[$i]['code'];
                }
            }

            sort($following);

            self::assertSame(
                $this->airportsOffered((string) $city['code']),
                $following,
                $city['label'] . ' is not followed by exactly the airports it expands to',
            );
        }
    }

    /**
     * One city code is one city, named the way the rest of the app names it.
     *
     * The picker used to group by the code *and* each airport's own `city`
     * column, which split any code whose airports disagree -- one does, NYC.
     * `CityRepository` has always grouped by the code alone over the identical
     * filter, so the two disagreed about how many cities there are and what
     * one is called. A picker that labels a place differently from the page it
     * leads to is two answers to one question.
     */
    public function testTheCityNamesAgreeWithTheRestOfTheApp(): void
    {
        $canonical = new CityRepository($this->connection())->names();

        $labels = [];

        foreach ($this->repository()->pickable() as $place) {
            if ((int) $place['is_city'] === 1) {
                $labels[(string) $place['code']] = (string) $place['label'];
            }
        }

        self::assertNotEmpty($labels, 'no multi-airport city in the data');

        foreach ($labels as $code => $label) {
            self::assertSame($canonical[$code] ?? null, $label, $code . ' is called two different things');
        }
    }

    /**
     * Every row says which city it is in, by code.
     *
     * The picker indents an airport under its city when both are on screen,
     * and it decides that by comparing `data-in-city` against the city rows in
     * the filtered list. Matching on the displayed name would work today --
     * no two sellable cities share one -- and would break silently the day two
     * did, so the relationship travels as the code.
     */
    public function testEveryPlaceSaysWhichCityItIsIn(): void
    {
        $places = $this->repository()->pickable();

        self::assertNotEmpty($places);

        foreach ($places as $place) {
            self::assertArrayHasKey('city_code', $place, $place['code'] . ' does not say');
            self::assertNotSame('', (string) $place['city_code'], $place['code'] . ' says nothing');

            if ((int) $place['is_city'] === 1) {
                self::assertSame(
                    (string) $place['code'],
                    (string) $place['city_code'],
                    'a city is in itself',
                );
            }
        }
    }

    /**
     * The airports a city row stands for, as the list offers them: everything
     * in the city bar the one whose code *is* the city code, which pickable()
     * drops because picking it already searches the whole city.
     *
     * @return list<string>
     */
    private function airportsOffered(string $cityCode): array
    {
        $codes = array_column(
            $this->connection()->fetchAll(
                'SELECT code FROM airports WHERE city_code = ? AND enabled = 1 AND is_major = 1'
                . ' AND code <> city_code ORDER BY code',
                [$cityCode],
            ),
            'code',
        );

        return array_map(strval(...), $codes);
    }

    private function repository(): AirportRepository
    {
        return new AirportRepository($this->connection());
    }
    /**
     * One airport per city, which is the rule the footer column rests on.
     *
     * London holds three of the four most-searched airports in this data --
     * Heathrow, Gatwick, Stansted -- so ranked airport by airport the column is
     * a list of London. Rank inside the city and take the winner and it is six
     * places instead of two.
     *
     * The trap this guards is the version that filters on the city's maximum
     * rather than ranking within it. It looks equivalent and passes on a
     * database with searches in it; on a fresh one every airport in a city is
     * level on nought, every one of them equals its own maximum, and the column
     * fills with one city again.
     */
    public function testMostSearchedReturnsOneAirportPerCity(): void
    {
        $airports = $this->repository()->mostSearched(12);

        self::assertNotEmpty($airports);

        $cities = array_column($airports, 'city');

        self::assertSame(array_unique($cities), $cities, 'two airports of one city are in the list');
    }

    /**
     * Busiest first, and never one we do not sell.
     */
    public function testMostSearchedIsOrderedAndSellable(): void
    {
        $airports = $this->repository()->mostSearched(8);

        self::assertNotEmpty($airports);
        self::assertLessThanOrEqual(8, count($airports));

        $hits = array_map(static fn(array $a): int => (int) $a['search_count'], $airports);
        $sorted = $hits;
        rsort($sorted);

        self::assertSame($sorted, $hits);

        $sellable = array_column($this->repository()->enabled(true), 'code');

        foreach ($airports as $airport) {
            self::assertContains($airport['code'], $sellable);
        }
    }

}
