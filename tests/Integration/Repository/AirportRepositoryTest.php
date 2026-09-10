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
     * The place nearest a point, which is what fills the homepage's field.
     *
     * The coordinates are from a real request through Cloudflare -- downtown
     * Montreal -- so this is the actual path rather than a plausible one.
     * Montreal offers one airport, so the answer is that airport; London
     * offers three, so the answer is the city, which searches all of them.
     */
    public function testTheNearestPlaceToAPointIsSomethingThePickerOffers(): void
    {
        $offered = array_column($this->repository()->pickable(), 'code');

        foreach ([
            'downtown Montreal' => [45.50884, -73.58781, 'YUL'],
            'central London' => [51.5074, -0.1278, 'LON'],
        ] as $where => [$latitude, $longitude, $expected]) {
            $found = $this->repository()->nearestPlaceTo($latitude, $longitude, 100);

            self::assertSame($expected, $found, $where);
            self::assertContains((string) $found, $offered, $where . ' is not selectable');
        }
    }

    /**
     * Past the radius the field is left alone rather than filled wide.
     *
     * 0,0 is the case worth naming: it is a common "unknown" sentinel, and it
     * needs no special handling because the nearest place we sell from is
     * hundreds of kilometres away in the Gulf of Guinea. The radius refuses
     * it like any other point at sea.
     */
    public function testAPointWithNothingNearbyFillsNothing(): void
    {
        foreach ([
            'the Gulf of Guinea, which is what 0,0 is' => [0.0, 0.0],
            'the middle of the Atlantic' => [30.0, -40.0],
            'Ottawa, 164km from Montreal' => [45.4215, -75.6972],
        ] as $where => [$latitude, $longitude]) {
            // Ottawa is its own sellable city, so it answers for itself -- the
            // point of naming it is that Montreal is *not* the answer.
            $found = $this->repository()->nearestPlaceTo($latitude, $longitude, 100);

            self::assertNotSame('YUL', $found, $where . ' should not reach Montreal');
        }

        self::assertNull($this->repository()->nearestPlaceTo(0.0, 0.0, 100), 'nothing within 100km of 0,0');
        self::assertNull($this->repository()->nearestPlaceTo(30.0, -40.0, 100));

        // And the radius is the reason, not the absence of anywhere to find.
        // Widened, 0,0 does answer -- so a bound that stopped being applied
        // would start filling fields from the middle of the sea, which the
        // two assertions above cannot tell from a query that simply failed.
        self::assertNotNull(
            $this->repository()->nearestPlaceTo(0.0, 0.0, 20_000),
            'the whole world is inside 20,000km, so the bound is what refuses 100',
        );
    }

    /**
     * Handing over the picker's rows changes the answer not at all.
     *
     * Both readers take them so the homepage can fetch once and ask three
     * questions -- pickable() is a 266-row group-by -- and an optimisation
     * that quietly answered differently would be worse than the cost it saves.
     */
    public function testPassingThePlacesInAnswersTheSame(): void
    {
        $places = $this->repository()->pickable();

        self::assertSame(
            $this->repository()->nearestPlaceTo(45.50884, -73.58781, 100),
            $this->repository()->nearestPlaceTo(45.50884, -73.58781, 100, $places),
        );

        self::assertSame(
            $this->repository()->nearbyPlaces('JFK', 4, 300),
            $this->repository()->nearbyPlaces('JFK', 4, 300, $places),
        );
    }

    /**
     * The nearby block leads with the city the field is open on.
     *
     * Its own other airports are the most useful answer to "what else is near
     * here" -- `nearby()`'s note puts it as "Heathrow's most useful
     * alternative is Gatwick" -- so the home city comes first and the rest
     * follow by distance.
     */
    public function testTheNearbyBlockLeadsWithTheCityTheFieldIsOn(): void
    {
        $places = $this->repository()->pickable();
        $anchor = $this->anAirportInAMultiAirportCity($places);

        $block = $this->repository()->nearbyPlaces($anchor['code'], 4, 300);

        self::assertNotEmpty($block, $anchor['code'] . ' should have neighbours');
        self::assertSame($anchor['city_code'], $block[0], 'the block should open with the home city');

        $own = $this->airportsOffered($anchor['city_code']);

        self::assertSame(
            $own,
            array_values(array_intersect(array_slice($block, 1, count($own)), $own)),
            'the home city should be followed by its own airports before anywhere else',
        );
    }

    /**
     * The field's own place is in the block, even where its city holds only it.
     *
     * Found by a surviving mutant. Dropping the home city from the front of
     * the order changed nothing for a multi-airport anchor -- its own airports
     * are the nearest neighbours, so the city arrives anyway -- and silently
     * removed the anchor from the block everywhere else. Montreal would have
     * offered Ottawa and not said where you already are.
     */
    public function testTheAnchorIsInItsOwnBlockEvenAloneInItsCity(): void
    {
        $anchor = null;

        // Genuinely alone, which means no city row above it. "One offered
        // airport" is not the same test and is what this first asked: Brussels
        // offers only Charleroi -- BRU is dropped for sharing the city code --
        // but it *has* a city row, so its neighbour list contains its own city
        // and the mutant survived. A city with one airport has no row at all.
        $hasCityRow = [];

        foreach ($this->repository()->pickable() as $place) {
            if ((int) $place['is_city'] === 1) {
                $hasCityRow[(string) $place['city_code']] = true;
            }
        }

        foreach ($this->airportRows() as $place) {
            $city = (string) $place['city_code'];

            if (isset($hasCityRow[$city])) {
                continue;
            }

            if ($this->repository()->nearbyPlaces((string) $place['code'], 4, 300) !== []) {
                $anchor = (string) $place['code'];

                break;
            }
        }

        self::assertNotNull($anchor, 'no single-airport city with a neighbour in the data');
        self::assertContains($anchor, $this->repository()->nearbyPlaces($anchor, 4, 300));
    }

    /**
     * A city anchor answers the same as its airports.
     *
     * Somebody can pick "New York" rather than an airport, and `nearby()`
     * measures from a row in the airports table -- so a city has to resolve to
     * one. Airports of a city are a few kilometres apart, so which one it
     * resolves to cannot change who the neighbours are.
     */
    public function testACityAnchorsWhereItsAirportsDo(): void
    {
        $places = $this->repository()->pickable();
        $anchor = $this->anAirportInAMultiAirportCity($places);

        self::assertSame(
            $this->repository()->nearbyPlaces($anchor['city_code'], 4, 300),
            $this->repository()->nearbyPlaces($anchor['code'], 4, 300),
        );
    }

    /**
     * Nothing within reach means no block, rather than a wider search.
     *
     * `nearby()` chose 300km because that is a drive somebody would make, and
     * says 102 of the 254 airports have no neighbour at all. Composing over it
     * must not quietly change that number.
     */
    public function testAnAirportWithNothingWithinReachOffersNoBlock(): void
    {
        $dropped = 0;

        // Airport rows only, on both sides of the comparison. nearby() takes
        // an airport code -- it measures from a row in the airports table --
        // and answers nothing for a city code that is not also an airport,
        // which is the whole reason nearbyPlaces() resolves the anchor first.
        // Counting city rows here compared 114 against 102 and looked like a
        // defect in the composition rather than in the reading.
        foreach ($this->airportRows() as $place) {
            if ($this->repository()->nearbyPlaces((string) $place['code'], 4, 300) === []) {
                $dropped++;
            }
        }

        self::assertGreaterThan(0, $dropped, 'somewhere should be too remote to offer alternatives');

        self::assertSame(
            $dropped,
            $this->anchorsWithNoNeighbour(),
            'the block should drop exactly where nearby() finds nothing, and nowhere else',
        );
    }

    /**
     * Everything the block names is something the picker can actually offer,
     * and a city's airports stay together inside it.
     */
    public function testTheBlockIsMadeOfRowsThePickerOffers(): void
    {
        $places = $this->repository()->pickable();
        $offered = array_column($places, 'code');
        $cityOf = array_column($places, 'city_code', 'code');

        $checked = 0;

        foreach ($places as $place) {
            $block = $this->repository()->nearbyPlaces((string) $place['code'], 4, 300);

            if ($block === []) {
                continue;
            }

            $checked++;

            self::assertEmpty(array_diff($block, $offered), 'the block names something unpickable');

            // Contiguous by city: the same grouping the list itself draws.
            $seen = [];
            $previous = null;

            foreach ($block as $code) {
                $city = $cityOf[$code];

                if ($city !== $previous) {
                    self::assertNotContains($city, $seen, $city . ' is split across the block');
                    $seen[] = $city;
                    $previous = $city;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no block was drawn to check');
    }

    /** How many airports nearby() finds no neighbour for. */
    private function anchorsWithNoNeighbour(): int
    {
        $none = 0;

        foreach ($this->airportRows() as $place) {
            if ($this->repository()->nearby((string) $place['code'], 16, 300) === []) {
                $none++;
            }
        }

        return $none;
    }

    /**
     * The picker's airport rows, without the city rows above them.
     *
     * @return list<array<string, mixed>>
     */
    private function airportRows(): array
    {
        return array_values(array_filter(
            $this->repository()->pickable(),
            static fn(array $place): bool => (int) $place['is_city'] === 0,
        ));
    }

    /**
     * @param list<array<string, mixed>> $places
     * @return array{code: string, city_code: string}
     */
    private function anAirportInAMultiAirportCity(array $places): array
    {
        foreach ($places as $place) {
            if ((int) $place['is_city'] !== 1) {
                continue;
            }

            $own = $this->airportsOffered((string) $place['city_code']);

            if (count($own) > 1) {
                return ['code' => $own[0], 'city_code' => (string) $place['city_code']];
            }
        }

        self::fail('no city offering more than one airport in the data');
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
