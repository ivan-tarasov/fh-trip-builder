<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\CabinClass;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * One route, which is the only place page that is not a row anywhere.
 *
 * A city, a country, an airport and an airline are each a record with a page
 * attached. A route is a pair, and there are 42,578 ordered city pairs in this
 * data with a direct flight between them -- so there is no table to read, no
 * directory that could list them and no sitemap that could carry them all. What
 * makes a route page exist is that you can fly it, which is a question about
 * the flights table and is asked here.
 *
 * Everything below names both ends. That is not a style choice: every index on
 * `flights` that covers a route leads with `departure_airport`, so a question
 * that does not name an origin cannot seek and scans all 683,760 rows. Naming
 * both turns each of these into a range scan of a few hundred -- measured at 5
 * to 10ms on the busiest pair in the data, New York to London, which has 226
 * upcoming flights across 9 airport pairs.
 *
 * Direct flights only, which is what makes the cheapest-dates query affordable
 * here when RoutePriceRepository has to cache its own. The difference is what
 * each is for: a calendar has to price every day, connections included, and
 * that takes seconds; a strip of the cheapest dates does not have to fill a day
 * it has no answer for. See cheapestDates().
 */
final readonly class RouteRepository
{
    /**
     * How many searched pairs to rank before asking which of them can be
     * flown.
     *
     * The filter is the expensive half, so it is applied to a shortlist rather
     * than to all 213 pairs anybody has searched. Forty is enough to survive
     * the filter with room to spare -- five of the 163 city pairs searched have
     * no nonstop, and the reciprocal pairs collapse on top of that.
     */
    private const int POPULAR_CANDIDATES = 40;

    public function __construct(private Connection $connection) {}

    /**
     * The routes people actually look for, busiest first.
     *
     * Counted, not curated, which for this footer column is the difference
     * between a list that maintains itself and a list somebody has to remember
     * to edit. The city column beside it has worked this way since the app's
     * first search.
     *
     * Every row is filtered to a pair that has a page. That is the whole reason
     * this is not just an ORDER BY over `search`: a search is recorded for any
     * pair somebody asked about, and the route page only exists where you can
     * fly it nonstop -- five of the 163 searched city pairs cannot be. Without
     * the filter this column would put a 404 in the footer of every page on the
     * site, which is the exact bug FooterRenderTest was written for.
     *
     * One direction per city pair. New York to London and London to New York
     * are two real pages with two real prices, and in a five-line footer they
     * are also two lines saying nearly the same thing -- so the busier of the
     * two stands for the pair and the other is reached from the page itself.
     *
     * The name is MIN(city) over the city's airports, the same rule
     * CityRepository::namesSql() states -- grouped here rather than joined to
     * it, which measured 4.7ms against 9.7ms for the same twelve rows.
     *
     * @return list<array<string, mixed>>
     */
    public function popular(int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT p.from_code, MIN(o.city) AS from_name,'
            . ' p.to_code, MIN(d.city) AS to_name, p.searches'
            . ' FROM ('
            . '  SELECT from_code, to_code, SUM(search_count) AS searches'
            . '  FROM ' . Table::Search->value
            . '  WHERE from_code <> to_code'
            . '  GROUP BY from_code, to_code'
            . '  ORDER BY searches DESC'
            . '  LIMIT ' . self::POPULAR_CANDIDATES
            . ' ) p'
            // A search stores whatever the form submitted, which may be an
            // airport code. Joining on city_code is what keeps this to pairs of
            // cities -- an airport code matches no city and drops out.
            . ' JOIN ' . Table::Airports->value . ' o'
            . '  ON o.city_code = p.from_code AND' . self::sellable('o')
            . ' JOIN ' . Table::Airports->value . ' d'
            . '  ON d.city_code = p.to_code AND' . self::sellable('d')
            . ' WHERE EXISTS ('
            . '  SELECT 1 FROM ' . Table::Flights->value . ' f'
            . '  WHERE f.departure_airport IN ('
            . '   SELECT a.code FROM ' . Table::Airports->value . ' a'
            . '   WHERE a.city_code = p.from_code AND' . self::sellable('a')
            . '  ) AND f.arrival_airport IN ('
            . '   SELECT b.code FROM ' . Table::Airports->value . ' b'
            . '   WHERE b.city_code = p.to_code AND' . self::sellable('b')
            . '  ) AND f.departure_time >= NOW()'
            . ' )'
            . ' GROUP BY p.from_code, p.to_code, p.searches'
            . ' ORDER BY p.searches DESC',
        );

        return array_slice(self::oneDirectionPerPair($rows), 0, max(0, $limit));
    }

    /**
     * The busier direction of each city pair, in the order they arrived.
     *
     * Done here rather than in SQL because "the same pair either way round" is
     * not something a GROUP BY expresses without sorting the two codes into a
     * key, and the rows are already ranked and few.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function oneDirectionPerPair(array $rows): array
    {
        $seen = [];
        $kept = [];

        foreach ($rows as $row) {
            $pair = [(string) $row['from_code'], (string) $row['to_code']];
            sort($pair);
            $key = implode('-', $pair);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * What there is to say about the route, or null when there is nothing.
     *
     * Null is the answer that decides whether the page exists at all. A pair
     * with no upcoming flight is not a route we sell, and a page saying so
     * would be 42,578 pages of nothing for a crawler to find.
     *
     * The typical duration and not the fastest one. The fastest is the more
     * flattering number and it is the wrong promise: New York to London runs
     * 374 minutes at best and averages 411, and a page that says 374 is
     * describing one flight in 226.
     *
     * @param list<string> $from
     * @param list<string> $to
     * @return array<string, mixed>|null
     */
    public function summary(array $from, array $to, CabinClass $cabin): ?array
    {
        if ($from === [] || $to === []) {
            return null;
        }

        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS flights,'
            . ' COUNT(DISTINCT f.airline) AS carriers,'
            . ' ROUND(AVG(f.duration)) AS typical,'
            . ' MIN(f.distance) AS km,'
            . ' MIN(' . self::fare('f', $cabin) . ') AS cheapest'
            . self::source()
            . self::filter($from, $to, $cabin),
            [...$from, ...$to],
        );

        // COUNT over an empty set is a row saying zero, not the absence of a
        // row -- so the emptiness has to be read off the count and not off the
        // fetch.
        return $row === null || (int) $row['flights'] === 0 ? null : $row;
    }

    /**
     * Who flies it, busiest first.
     *
     * Joined to `airlines` rather than left as codes, because the chips this
     * fills link to the airline's own page and need its name to do it. An inner
     * join, and `is_major` with it: those two together are what "an airline we
     * sell" means, and a chip pointing at a carrier with no page would be a
     * link to a 404.
     *
     * @param list<string> $from
     * @param list<string> $to
     * @return list<array<string, mixed>>
     */
    public function carriers(array $from, array $to, CabinClass $cabin): array
    {
        if ($from === [] || $to === []) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT f.airline AS code, al.title AS name, COUNT(*) AS flights'
            . self::source(' JOIN ' . Table::Airlines->value . ' al ON al.code = f.airline')
            . self::filter($from, $to, $cabin)
            . ' AND al.is_major = 1'
            . ' GROUP BY f.airline, al.title'
            . ' ORDER BY flights DESC, name ASC',
            [...$from, ...$to],
        );
    }

    /**
     * The airports actually used at one end of the route.
     *
     * Ends rather than pairs. New York to London runs over 9 airport pairs and
     * only 6 airports, and "JFK to Stansted, JFK to Gatwick, JFK to Heathrow,
     * Newark to Stansted..." is a list nobody reads -- where you can leave from
     * and where you can land are the two things being asked.
     *
     * `$arriving` picks the end: false for the airports flown from, true for
     * the ones flown into. One method rather than two, because it is the same
     * query read from either side and two copies would be two places to keep
     * the cabin filter right.
     *
     * @param list<string> $from
     * @param list<string> $to
     * @return list<array<string, mixed>>
     */
    public function ends(array $from, array $to, CabinClass $cabin, bool $arriving): array
    {
        if ($from === [] || $to === []) {
            return [];
        }

        $column = $arriving ? 'f.arrival_airport' : 'f.departure_airport';

        return $this->connection->fetchAll(
            'SELECT a.code, a.title, COUNT(*) AS flights'
            . self::source(' JOIN ' . Table::Airports->value . ' a ON a.code = ' . $column)
            . self::filter($from, $to, $cabin)
            . ' GROUP BY a.code, a.title'
            . ' ORDER BY flights DESC, a.title ASC',
            [...$from, ...$to],
        );
    }

    /**
     * The cheapest seat on each day the route has one, cheapest day first.
     *
     * Ranked per day and then sorted by price, which is the whole point. A
     * calendar has to show every day in order and looks broken where a quiet
     * route has nothing: Montreal to Vancouver answers on 19 days out of the
     * next three months, so a calendar of it is mostly gaps. A strip sorted by
     * price has no gaps to show, because a day with no flight simply is not one
     * of the cheapest days.
     *
     * The whole flight and not just its price, because each card names the
     * airline, the airports and the times. The airports are what varies down
     * the strip on a route with more than one pair, and they are why this is
     * not the shared fares block: there, each card names the two cities, and on
     * a route page the two cities are the page's own title.
     *
     * @param list<string> $from
     * @param list<string> $to
     * @return list<array<string, mixed>>
     */
    public function cheapestDates(array $from, array $to, CabinClass $cabin, int $limit): array
    {
        if ($from === [] || $to === []) {
            return [];
        }

        $fare = self::fare('f', $cabin);

        return $this->connection->fetchAll(
            'SELECT x.* FROM ('
            . ' SELECT DATE(f.departure_time) AS depart_date, f.airline,'
            . '  f.departure_airport, f.arrival_airport,'
            . '  f.departure_time, f.arrival_time, f.duration,'
            . '  ' . $fare . ' AS total,'
            . '  ROW_NUMBER() OVER ('
            . '   PARTITION BY DATE(f.departure_time)'
            . '   ORDER BY ' . $fare . ' ASC, f.departure_time ASC'
            . '  ) AS rn'
            . self::source()
            . self::filter($from, $to, $cabin)
            . ' ) x WHERE x.rn = 1'
            . ' ORDER BY x.total ASC, x.depart_date ASC'
            . ' LIMIT ' . max(1, $limit),
            [...$from, ...$to],
        );
    }

    /**
     * The table the four questions are asked of, plus whatever one of them
     * needs beside it.
     *
     * The extra join is a parameter rather than glued on by the caller because
     * a join belongs before the WHERE clause and the filter below carries that
     * -- appending one after it is invalid SQL, which is how the first draft of
     * ends() was written.
     */
    private static function source(string $joins = ''): string
    {
        return ' FROM ' . Table::Flights->value . ' f' . $joins;
    }

    /**
     * "from one of these airports to one of those, and bookable".
     *
     * Spelled once so the four queries cannot disagree about what counts --
     * most of all about `departure_time >= NOW()`, which is what keeps a fare
     * nobody can buy off a page whose only job is to sell one.
     *
     * The cabin test comes from CabinClass::sqlOffers(), which returns nothing
     * for economy: every flight sells it, so the predicate would exclude
     * nothing while denying the optimiser an index.
     *
     * @param list<string> $from
     * @param list<string> $to
     */
    private static function filter(array $from, array $to, CabinClass $cabin): string
    {
        $offers = $cabin->sqlOffers('f');

        return ' WHERE f.departure_airport IN (' . self::placeholders($from) . ')'
            . '  AND f.arrival_airport IN (' . self::placeholders($to) . ')'
            . '  AND f.departure_time >= NOW()'
            . ($offers === null ? '' : ' AND ' . $offers);
    }

    /**
     * One flight's total in a cabin.
     *
     * The uplift is not flat -- short-haul business is a wider seat and
     * long-haul business is a bed -- so CabinClass scales it by distance, and
     * the same expression the search uses is used here. Base and tax are
     * multiplied separately, the way RoutePriceRepository does it, because that
     * is the shape the two halves are stored in.
     */
    private static function fare(string $alias, CabinClass $cabin): string
    {
        $multiplier = $cabin->sqlPriceMultiplier($alias);
        $base = $alias . '.price_base';
        $tax = $alias . '.price_tax';

        return $multiplier === null
            ? $base . ' + ' . $tax
            : $base . ' * ' . $multiplier . ' + ' . $tax . ' * ' . $multiplier;
    }

    /**
     * The filter the whole site sells by, for one alias.
     *
     * A method where the other repositories carry a constant, because
     * popular() names the airports table four times over and each copy needs
     * its own alias.
     */
    private static function sellable(string $alias): string
    {
        return sprintf(' %s.enabled = 1 AND %s.is_major = 1', $alias, $alias);
    }

    /** @param list<string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
