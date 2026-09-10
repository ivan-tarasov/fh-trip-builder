<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final readonly class AirportRepository
{
    /**
     * `city` is the city's name, not this airport's idea of it.
     *
     * Newark Liberty reports "Newark" where the other two airports of NYC
     * report "New York", and the city this app has a page for is New York. Read
     * straight off the row, that one airport made its own page's breadcrumb
     * link to a redirect and made three other pages print a name no page of
     * ours answers to. See CityRepository::namesSql().
     */
    private const string COLUMNS = 'a.code, a.title, c.title AS country, a.city_code, '
        . 'COALESCE(cc.name, a.city) AS city, '
        . 'a.timezone, a.timezone_name, a.latitude, a.longitude, a.altitude';

    /** The filter the whole site sells by, as CityRepository spells it. */
    private const string ONLY_SELLABLE = ' a.enabled = 1 AND a.is_major = 1';

    /**
     * The tables COLUMNS is read from.
     *
     * A method rather than a constant because the city-name subquery is built,
     * not written out. LEFT JOIN and COALESCE on it, not a plain JOIN: the
     * subquery covers major airports only, and enabled(false) -- which the
     * airports endpoint reaches -- would otherwise drop every minor airport in
     * a city that has no major one.
     */
    private static function source(): string
    {
        return ' FROM ' . Table::Airports->value . ' a'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' LEFT JOIN (' . CityRepository::namesSql() . ') cc ON cc.code = a.city_code';
    }

    public function __construct(private Connection $connection) {}

    /**
     * The airports people search for, one per city, busiest first.
     *
     * `search_count` is written by the place picker every time somebody picks
     * an airport, so this is demand rather than a list to keep up to date.
     *
     * One per city, which is the part worth explaining. London has three of the
     * four most-searched airports in this data -- Heathrow, Gatwick, Stansted
     * -- so ranked airport by airport the column would be a list of London.
     * Taking each city's busiest gives Heathrow, Trudeau, Vancouver, Newark,
     * Tullamarine, Charles De Gaulle: six places rather than two. Same rule the
     * Directions column uses to keep both halves of a pair off one list.
     *
     * `traffic_weight` behind the count, so a database nobody has searched yet
     * still opens on the airports worth naming instead of on whatever the table
     * hands back first.
     *
     * @return list<array<string, mixed>>
     */
    public function mostSearched(int $limit): array
    {
        return $this->connection->fetchAll(
            'SELECT x.* FROM ('
            . ' SELECT ' . self::COLUMNS . ', a.search_count, a.traffic_weight,'
            // Ranked inside the city rather than filtered against its maximum.
            // The filter looks equivalent and is not: on a database nobody has
            // searched yet every airport in a city is level on nought, they all
            // match their own maximum, and the column fills with one city's
            // airports again -- which is the thing this rule exists to stop.
            . '  ROW_NUMBER() OVER ('
            . '   PARTITION BY a.city_code'
            . '   ORDER BY a.search_count DESC, a.traffic_weight DESC, a.title ASC'
            . '  ) AS rn'
            . self::source()
            . ' WHERE' . self::ONLY_SELLABLE
            . ' ) x WHERE x.rn = 1'
            . ' ORDER BY x.search_count DESC, x.traffic_weight DESC, x.title ASC'
            . ' LIMIT ' . max(1, $limit),
        );
    }

    /**
     * Enabled airports (optionally major only), joined to their country,
     * ordered by title.
     *
     * @return list<array<string, mixed>>
     */
    public function enabled(bool $majorOnly): array
    {
        $sql = 'SELECT ' . self::COLUMNS . self::source()
            . ' WHERE a.enabled = 1';

        if ($majorOnly) {
            $sql .= ' AND is_major = 1';
        }

        $sql .= ' ORDER BY a.title ASC';

        return $this->connection->fetchAll($sql);
    }

    public function countEnabled(bool $majorOnly): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . Table::Airports->value . ' a WHERE a.enabled = 1';

        if ($majorOnly) {
            $sql .= ' AND is_major = 1';
        }

        return (int) $this->connection->fetchValue($sql);
    }

    /**
     * Everywhere a search can start or end: the cities the network serves and
     * the airports inside them, as one ordered list.
     *
     * Small enough to ship whole -- 254 major airports across 231 cities, which
     * is every airport that has a departure. That is what lets the form use a
     * plain select the browser filters instantly, instead of asking the server
     * after every third keystroke.
     *
     * A city sorts immediately above its own airports, so picking "London"
     * (which resolveAirportCodes() expands to all three) sits with the three it
     * expands to rather than somewhere else alphabetically.
     *
     * One city code is one city, and the name comes from
     * CityRepository::namesSql() rather than from each airport's own `city`
     * column. This used to group by the code *and* the name, which split any
     * code whose airports disagreed -- NYC, where EWR says "Newark" and JFK
     * and LGA say "New York". The list then offered two airports under New
     * York while picking it searched three, since resolveAirportCodes() matches
     * `code = ? OR city_code = ?`; and EWR could not be found by typing "New
     * York" at all, which the ranking in global.js says is exactly wrong --
     * "an airport is looked up by its city at least as often as by its own
     * name, and 'Montreal' must find Trudeau".
     *
     * Joining that derived table is the idiom the search SQL already uses, and
     * it means the city's name, the sort key and each airport's sub line come
     * from one definition instead of three. `/city/new-york-nyc` is the same
     * name, because it is the same source.
     *
     * @return list<array<string, mixed>>
     */
    public function pickable(): array
    {
        $cities = CityRepository::namesSql();

        $sql = 'SELECT code, label, sub, city, city_code, is_city FROM ('
            . ' SELECT a.city_code AS code, cn.name AS label, MIN(c.title) AS sub, cn.name AS city,'
            . '  a.city_code AS city_code, 1 AS is_city, cn.name AS in_city, 0 AS depth'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' JOIN (' . $cities . ') cn ON cn.code = a.city_code'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE a.enabled = 1 AND a.is_major = 1'
            . ' GROUP BY a.city_code, cn.name'
            // Only where it means something. A city with one airport shares that
            // airport's code, so the row would be a second way to pick the same
            // place -- and two options carrying one value is a list that looks
            // like it offers a choice it does not.
            . ' HAVING COUNT(*) > 1'
            . ' UNION ALL'
            // The sub line and the sort key are the city's name, not the
            // airport's own municipality: "Newark Liberty International --
            // New York, United States" is how every flight search presents
            // it, the airport's title already says Newark, and it is what
            // makes typing "New York" reach it.
            . ' SELECT a.code, a.title, CONCAT(cn.name, \', \', c.title), cn.name,'
            . '  a.city_code, 0, cn.name, 1'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' JOIN (' . $cities . ') cn ON cn.code = a.city_code'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON a.country_code = c.code'
            . ' WHERE a.enabled = 1 AND a.is_major = 1'
            // In a multi-airport city, one airport often carries the city's own
            // code -- BER, BKK, BRU, CTU, IST, SHA. resolveAirportCodes()
            // matches `code = ? OR city_code = ?`, so picking that airport
            // already searches every airport in the city: the row offers one
            // airport and delivers all of them. The city row above says so
            // honestly, and this drops the row that does not.
            //
            // Only where a city row exists. In a single-airport city the
            // airport's code IS the city code, and dropping those would empty
            // the list of most of its airports.
            . ' AND NOT (a.code = a.city_code AND EXISTS ('
            . '  SELECT 1 FROM ' . Table::Airports->value . ' peer'
            . '  WHERE peer.city_code = a.city_code AND peer.enabled = 1'
            . '   AND peer.is_major = 1 AND peer.code <> a.code'
            . ' ))'
            . ') places ORDER BY in_city ASC, depth ASC, label ASC';

        return $this->connection->fetchAll($sql);
    }

    /**
     * Airports for a set of codes, in the same shape as enabled(). Used to
     * label the codes a search offered, so the sidebar can show a city and
     * country rather than three letters.
     *
     * @param list<string> $codes
     * @return list<array<string, mixed>>
     */
    public function byCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($codes), '?'));

        return $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . self::source()
            . " WHERE a.code IN ($placeholders)"
            . ' ORDER BY a.city ASC, a.title ASC',
            array_values($codes),
        );
    }

    /**
     * Bump the search counter for the given departure/arrival airports,
     * matched by airport code or city code.
     */
    public function recordSearch(string ...$codes): void
    {
        $in = implode(', ', array_fill(0, count($codes), '?'));

        $this->connection->execute(
            'UPDATE ' . Table::Airports->value
            . ' SET search_count = search_count + 1, last_search = NOW()'
            . " WHERE code IN ($in) OR city_code IN ($in)",
            [...$codes, ...$codes],
        );
    }

    /**
     * City name for an airport code or city code (first match), or null.
     */
    public function cityByCode(string $code): ?string
    {
        $city = $this->connection->fetchValue(
            'SELECT city FROM ' . Table::Airports->value . ' WHERE code = ? OR city_code = ? LIMIT 1',
            [$code, $code],
        );

        return $city === null ? null : (string) $city;
    }

    /**
     * One airport, by its IATA code.
     *
     * `country_code` on top of enabled()'s columns, because the page's
     * breadcrumb has to link the country it sits in and the name alone will not
     * spell an address.
     *
     * @return array<string, mixed>|null
     */
    public function byCode(string $code): ?array
    {
        return $this->connection->fetchOne(
            'SELECT ' . self::COLUMNS . ', a.country_code, a.traffic_weight' . self::source()
            . ' WHERE' . self::ONLY_SELLABLE . ' AND a.code = ?',
            [strtoupper($code)],
        );
    }

    /**
     * The airports somebody might drive to instead, nearest first.
     *
     * A much tighter radius than the city page's thousand kilometres, because
     * this is a different question. A nearby city is somewhere else to fly
     * from; a nearby airport is somewhere else to leave from, and past a
     * three-hour drive nobody does. 300km is that drive.
     *
     * It leaves 102 of the 254 airports with no neighbour at all, and the block
     * drops for them rather than reaching further to fill itself: at 500km the
     * answers stop being drives, and 500km of driving to save a fare is not
     * advice worth printing. Where there are neighbours the answers read right
     * -- Gatwick, Stansted and Luton for Heathrow; nothing for Honolulu.
     *
     * Airports of the same city are included and are usually the first two.
     * That is the point: Heathrow's most useful alternative is Gatwick.
     *
     * @return list<array<string, mixed>>
     */
    public function nearby(string $code, int $limit, int $maxKm): array
    {
        return $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ','
            . ' ST_Distance_Sphere(POINT(here.longitude, here.latitude), POINT(a.longitude, a.latitude)) / 1000 AS km'
            . self::source()
            // The airport itself, joined to as a single row so its coordinates
            // can be named in the distance without a second round trip.
            . ' JOIN ' . Table::Airports->value . ' here ON here.code = ?'
            . ' WHERE' . self::ONLY_SELLABLE . ' AND a.code <> here.code'
            . ' HAVING km <= ?'
            . ' ORDER BY km ASC'
            // Interpolated, as elsewhere in this layer: LIMIT takes no
            // placeholder in an emulated prepare, and the caller has already
            // been forced to declare it an int.
            . ' LIMIT ' . max(1, $limit),
            [strtoupper($code), $maxKm],
        );
    }

    /**
     * Everything leaving this airport on one date, earliest first.
     *
     * A half-open window rather than DATE(departure_time) = ?, which is the
     * whole performance of this block: wrapped in DATE() the column cannot be
     * seeked and the query costs 7ms, where the range seeks
     * (departure_airport, departure_time) and costs 1.6ms for the same 50 rows.
     *
     * @return list<array<string, mixed>>
     */
    public function departures(string $code, string $date): array
    {
        return $this->connection->fetchAll(
            'SELECT f.airline, f.number, f.departure_time, f.arrival_time, f.duration,'
            . ' other.code AS other_code, other.title AS other_title,'
            . ' other.city AS other_city, other.city_code AS other_city_code'
            . ' FROM ' . Table::Flights->value . ' f'
            . ' JOIN ' . Table::Airports->value . ' other ON other.code = f.arrival_airport'
            // `other_city` is this airport's own idea of its city, and the
            // caller replaces it -- see AirportController::schedule(). Not
            // joined to CityRepository::namesSql() the way the columns above
            // are, because here it costs the plan: the derived table becomes
            // the driving table, arrival_airport_time stops being used at
            // all, and the query goes from 0.6ms to 19.5ms. One 1ms lookup
            // in PHP serves both directions instead.
            . ' WHERE f.departure_airport = ?'
            . ' AND f.departure_time >= ? AND f.departure_time < ? + INTERVAL 1 DAY'
            . ' ORDER BY f.departure_time ASC',
            [strtoupper($code), $date, $date],
        );
    }

    /**
     * Everything landing here on one date, earliest first.
     *
     * Filtered on when it lands, not when it left: a red-eye out of Vancouver
     * at 23:00 is tomorrow's arrival, and a board that filed it under yesterday
     * would be wrong on both days.
     *
     * This is what (arrival_airport, arrival_time) is in the schema for. Before
     * it the query was `type=ALL` over 683,760 rows at 9.6ms -- no index in the
     * table led with an arrival, which is the same gap
     * FlightRepository::cheapestDirectPerOrigin() works around by naming its
     * origins. With it the same 40 rows come back in 1.3ms.
     *
     * @return list<array<string, mixed>>
     */
    public function arrivals(string $code, string $date): array
    {
        return $this->connection->fetchAll(
            'SELECT f.airline, f.number, f.departure_time, f.arrival_time, f.duration,'
            . ' other.code AS other_code, other.title AS other_title,'
            . ' other.city AS other_city, other.city_code AS other_city_code'
            . ' FROM ' . Table::Flights->value . ' f'
            . ' JOIN ' . Table::Airports->value . ' other ON other.code = f.departure_airport'
            // `other_city` is this airport's own idea of its city, and the
            // caller replaces it -- see AirportController::schedule(). Not
            // joined to CityRepository::namesSql() the way the columns above
            // are, because here it costs the plan: the derived table becomes
            // the driving table, arrival_airport_time stops being used at
            // all, and the query goes from 0.6ms to 19.5ms. One 1ms lookup
            // in PHP serves both directions instead.
            . ' WHERE f.arrival_airport = ?'
            . ' AND f.arrival_time >= ? AND f.arrival_time < ? + INTERVAL 1 DAY'
            . ' ORDER BY f.arrival_time ASC',
            [strtoupper($code), $date, $date],
        );
    }
}
