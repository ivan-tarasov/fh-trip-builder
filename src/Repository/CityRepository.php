<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Cities, which are not a table.
 *
 * There is no `cities` table and this does not add one. A city is already fully
 * described by the airports that serve it: `airports` carries `city_code`, the
 * name, the country, the timezone and the coordinates, and it is indexed on
 * `city_code`. Adding a table would mean seeding a second copy of all of that
 * and keeping the two in step.
 *
 * The grouping is only sound because the columns agree within a city, which was
 * measured rather than hoped for: of the 231 major cities in the seed, none has
 * airports in two countries and none has airports in two timezones. That is
 * what lets MIN() stand in for "the city's country" below. If a city ever
 * straddles a border -- Basel is the classic -- this is the assumption that
 * breaks, and it breaks quietly.
 */
final readonly class CityRepository
{
    /**
     * Airports a visitor can actually be sold a seat on. The whole site filters
     * to major airports; a city page listing others would offer flights the
     * search cannot find.
     */
    private const string ONLY_SELLABLE = ' a.enabled = 1 AND a.is_major = 1';

    public function __construct(private Connection $connection) {}

    /**
     * One city, by its IATA code.
     *
     * @return array<string, mixed>|null
     */
    public function byCode(string $code): ?array
    {
        return $this->connection->fetchOne(
            'SELECT a.city_code AS code, MIN(a.city) AS name,'
            . ' MIN(a.country_code) AS country_code, MIN(c.title) AS country,'
            . ' MIN(a.timezone) AS timezone, MIN(a.timezone_name) AS timezone_name,'
            . ' COUNT(*) AS airports,'
            // The city sits at the middle of its airports. For the 213 cities
            // with one airport that is the airport; for the other 18 it is a
            // point between them, which is what a map should centre on and what
            // a distance to the next city should be measured from.
            . ' AVG(a.latitude) AS latitude, AVG(a.longitude) AS longitude'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE' . self::ONLY_SELLABLE . ' AND a.city_code = ?'
            . ' GROUP BY a.city_code',
            [strtoupper($code)],
        );
    }

    /**
     * The airports of one city, largest first.
     *
     * @return list<array<string, mixed>>
     */
    public function airports(string $code): array
    {
        return $this->connection->fetchAll(
            'SELECT a.code, a.title, a.city, a.latitude, a.longitude, a.traffic_weight'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' WHERE' . self::ONLY_SELLABLE . ' AND a.city_code = ?'
            . ' ORDER BY a.traffic_weight DESC, a.title ASC',
            [strtoupper($code)],
        );
    }

    /**
     * The nearest cities, and none that is not near.
     *
     * Two limits, because one is not enough. A count alone would call somewhere
     * 3,855km away a neighbour, which is the distance to the nearest city from
     * the most isolated place in this database. A radius alone would empty the
     * block: this database holds 231 major cities for the whole world, and its
     * median gap between neighbours is 296km -- at the 500km a denser dataset
     * can afford, 85% of cities would have fewer than five neighbours to show.
     *
     * Nearest 8 within 1,000km gives a median of six, and the answers read the
     * way a person would expect: Ottawa, Boston and Toronto for Montreal;
     * Manchester, Brussels and Paris for London; nothing at all for Honolulu,
     * which is the truth.
     *
     * @return list<array<string, mixed>>
     */
    public function nearby(string $code, int $limit, int $maxKm): array
    {
        $city = $this->byCode($code);

        if ($city === null) {
            return [];
        }

        // ST_Distance_Sphere answers in metres and takes POINT(longitude,
        // latitude) -- that order, which is the opposite of how coordinates are
        // written everywhere else in this codebase.
        return $this->connection->fetchAll(
            'SELECT city.*, ST_Distance_Sphere(POINT(?, ?), POINT(city.longitude, city.latitude)) / 1000 AS km'
            . ' FROM (' . $this->centroids() . ') city'
            . ' WHERE city.code <> ?'
            . ' HAVING km <= ?'
            . ' ORDER BY km ASC'
            // Interpolated, as elsewhere in this repository layer: LIMIT will
            // not take a placeholder in an emulated prepare, and the value is
            // an int the caller has already been forced to declare as one.
            . ' LIMIT ' . max(1, $limit),
            [
                (float) $city['longitude'],
                (float) $city['latitude'],
                strtoupper($code),
                $maxKm,
            ],
        );
    }

    /**
     * Every city we sell flights to, by country.
     *
     * The index exists because of a measurement rather than a wish: crawling
     * the link graph from the homepage reached 171 of the 231 city pages, and
     * the furthest took six hops. Sixty had no inbound link at all -- they were
     * reachable only by typing the URL. One page listing all of them puts every
     * city one hop from the footer.
     *
     * Grouped in PHP rather than by the query, because the grouping is only for
     * display and a second query per country would be 60 of them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function allByCountry(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT a.city_code AS code, MIN(a.city) AS name,'
            . ' MIN(a.country_code) AS country_code, MIN(c.title) AS country,'
            . ' COUNT(*) AS airports'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' GROUP BY a.city_code'
            . ' ORDER BY country ASC, name ASC',
        );

        $byCountry = [];

        foreach ($rows as $row) {
            $byCountry[(string) $row['country']][] = $row;
        }

        return $byCountry;
    }

    /**
     * The cities people look for most.
     *
     * `airports.search_count` is bumped per search by AirportRepository, and it
     * is bumped on the airport's own code and on its city code, so summing it
     * per city is the count of searches that named the city however they named
     * it. Nothing else in the app reads this column; it has been filling up
     * since the first search and this is the first thing to ask it a question.
     *
     * No index on search_count, so this is a scan and a filesort over 254 rows.
     * Measured at 0.8ms, which is what makes it affordable in a footer that is
     * on every page.
     *
     * @return list<array<string, mixed>>
     */
    public function mostSearched(int $limit): array
    {
        return $this->connection->fetchAll(
            'SELECT a.city_code AS code, MIN(a.city) AS name, SUM(a.search_count) AS hits'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' GROUP BY a.city_code'
            // Ties broken by name, so the list is stable between renders rather
            // than reshuffling whenever two cities are level.
            . ' ORDER BY hits DESC, name ASC'
            . ' LIMIT ' . max(1, $limit),
        );
    }

    /**
     * Airports of the cities somebody is most likely to be flying from.
     *
     * "Most likely" is traffic weight, not distance: the cheap-fares block is
     * about where the market is, and the nearest city to Montreal is Ottawa
     * while the one people actually fly in from is New York. Nearby cities have
     * their own block.
     *
     * Split by country because the page offers the two as tabs -- domestic
     * fares and everything else are different questions, and mixing them buries
     * the short cheap hops under the long expensive ones.
     *
     * Airport codes rather than city codes, because the flights table is keyed
     * on airports and only `departure_airport` can drive its index. Resolving
     * the cities to their airports here is what lets the fare query seek
     * instead of scanning 683,760 rows.
     *
     * @return list<string>
     */
    public function busiestOriginAirports(
        string $exceptCityCode,
        string $countryCode,
        bool $domestic,
        int $cityLimit,
    ): array {
        $cities = $this->connection->fetchAll(
            'SELECT a.city_code AS code, SUM(a.traffic_weight) AS weight, MIN(a.city) AS name'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' AND a.city_code <> ?'
            . ' AND a.country_code ' . ($domestic ? '=' : '<>') . ' ?'
            . ' GROUP BY a.city_code'
            . ' ORDER BY weight DESC, name ASC'
            . ' LIMIT ' . max(1, $cityLimit),
            [strtoupper($exceptCityCode), strtoupper($countryCode)],
        );

        if ($cities === []) {
            return [];
        }

        $codes = array_column($cities, 'code');

        return array_column(
            $this->connection->fetchAll(
                'SELECT a.code FROM ' . Table::Airports->value . ' a'
                . ' WHERE' . self::ONLY_SELLABLE
                . ' AND a.city_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')',
                $codes,
            ),
            'code',
        );
    }

    /**
     * Every sellable city as one point. 231 rows over a 1,091-row table, which
     * is why this can be grouped on the fly instead of stored.
     */
    private function centroids(): string
    {
        return 'SELECT a.city_code AS code, MIN(a.city) AS name,'
            . ' MIN(a.country_code) AS country_code, MIN(c.title) AS country,'
            . ' AVG(a.latitude) AS latitude, AVG(a.longitude) AS longitude'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' GROUP BY a.city_code';
    }
}
