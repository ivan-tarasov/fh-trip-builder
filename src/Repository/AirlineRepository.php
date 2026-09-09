<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Airlines, which like countries are a thin table read two ways.
 *
 * Most of what an airline page says is here and is curated rather than
 * counted: where it is based, which airports it flies out of, its website and
 * its phone number. What the flights table can add to that has to be chosen
 * carefully -- see network(), which is as much about the figures left out as
 * the ones kept.
 */
final readonly class AirlineRepository
{
    /**
     * The same filter the rest of the site sells by.
     *
     * `is_major` is exactly "we sell seats on it" and not an approximation of
     * it: measured on this data, all 105 major airlines have flights and no
     * other airline has a single one. So the flag can be trusted on its own,
     * without a join to the 683,760 rows that would prove it.
     */
    private const string ONLY_SELLABLE = ' al.is_major = 1';

    /**
     * How far ahead the two counted blocks look.
     *
     * The schedule holds 87 days, and counting all of them cost the airline
     * page more than every other page on the site put together -- 330ms for
     * American against 26ms for a city. Two weeks is a sixth of the rows and
     * measured 4 to 7 times faster on every airline tried.
     *
     * It costs nothing that matters. The aircraft list is ordered by route
     * length, which does not change with the season, and the peer list is
     * about who is present at an airport -- both came back with the same
     * story, and the only movement was between counts already within 3% of
     * each other.
     *
     * Two indexes were tried first and both were dropped: (airline,
     * departure_time) and a covering (airline, departure_time, aircraft). The
     * optimiser declined both, and forcing the first was *slower* than the
     * plan it had picked. Fewer rows was the answer, not another 10MB of index.
     *
     * It also gives the counts a period. A total over "whatever the schedule
     * holds" is a number nobody can put a date to; a fortnight is one the page
     * can name, and it does.
     */
    public const int WINDOW_DAYS = 14;

    public function __construct(private Connection $connection) {}

    /**
     * One airline, with the country it is based in.
     *
     * LEFT JOIN because `country` is nullable, even though all 105 airlines we
     * sell have one -- a null there should cost a page its "based in" tile, not
     * the whole page.
     *
     * @return array<string, mixed>|null
     */
    public function byCode(string $code): ?array
    {
        return $this->connection->fetchOne(
            'SELECT al.code, al.title AS name, al.url, al.phone, al.hubs,'
            . ' al.country AS country_code, c.title AS country'
            . ' FROM ' . Table::Airlines->value . ' al'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON c.code = al.country'
            . ' WHERE' . self::ONLY_SELLABLE . ' AND al.code = ?',
            [strtoupper($code)],
        );
    }

    /**
     * How much this airline flies out of its own hubs, and in what.
     *
     * Two figures, and the choosing was the work. Measured across all 105:
     *
     *   departures   2 to 157 a day        kept, an 80-fold spread
     *   widebody    34% to 95% of flights  kept, and it is what you sit in
     *   countries   38 to 93, median 87    dropped, top-heavy
     *   longest     12,646 to 15,299 km    dropped, the same number every time
     *
     * The longest flight is the one to look at. Every airline here has one of
     * about 15,000km, because every airline flies almost everywhere, so the
     * tile would have printed the same figure 105 times while looking like a
     * fact about the carrier. Countries is the same shape, milder. Departures
     * a day is the opposite: 2 for the quietest and 157 for American.
     *
     * A day, not a total, because a total counts whatever the schedule happens
     * to hold. The cities are counted by the controller already, as the
     * destinations the fares strip is the front of, so they are not counted a
     * second time here.
     *
     * Over WINDOW_DAYS, like the two blocks below: a rate does not care how
     * long you watch it for, and dropping the join to `airports` that the
     * countries figure needed took this from 58ms to single figures on the
     * busiest airline in the data.
     *
     * @param list<string> $hubs
     * @return array<string, mixed>|null
     */
    public function network(string $code, array $hubs): ?array
    {
        if ($hubs === []) {
            return null;
        }

        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) AS flights,'
            . ' COUNT(DISTINCT DATE(f.departure_time)) AS days,'
            . ' SUM(ac.is_widebody) AS widebody'
            . ' FROM ' . Table::Flights->value . ' f'
            . ' JOIN ' . Table::Aircraft->value . ' ac ON ac.code = f.aircraft'
            . ' WHERE f.airline = ? AND f.departure_airport IN (' . self::placeholders($hubs) . ')'
            . ' AND f.departure_time >= NOW()'
            . ' AND f.departure_time < NOW() + INTERVAL ' . self::WINDOW_DAYS . ' DAY',
            [strtoupper($code), ...$hubs],
        );

        $flights = (int) ($row['flights'] ?? 0);

        if ($row === null || $flights === 0) {
            return null;
        }

        return [
            'per_day' => (int) round($flights / max(1, (int) $row['days'])),
            'widebody_share' => (int) round((int) $row['widebody'] / $flights * 100),
        ];
    }

    /**
     * The aircraft this airline flies most, and how many flights each has.
     *
     * A fleet is the block an airline page obviously wants and the one this
     * data cannot honestly give: 104 of the 105 airlines fly all 28 types in
     * the table, so the *set* says nothing. What says something is the
     * *order*, because the seeder picks an aircraft by whether its range
     * covers the leg -- every type's longest flight in this data lands within
     * a few km of its `max_range_km`. So the types an airline flies most are a
     * restatement of how long its legs are, and that differs: easyJet is 44%
     * widebody with turboprops at the top, Qantas is 86% with A350s and A380s.
     *
     * Which is also why the block says "flights", never "aircraft owned". This
     * counts departures, not airframes, and the page has no idea how many of
     * anything an airline owns. Departures in the next WINDOW_DAYS days, which
     * the block prints so the count has a period.
     *
     * @return list<array<string, mixed>>
     */
    public function aircraft(string $code, int $limit): array
    {
        return $this->connection->fetchAll(
            'SELECT ac.title, ac.manufacturer, ac.is_widebody, COUNT(*) AS flights'
            . ' FROM ' . Table::Flights->value . ' f'
            . ' JOIN ' . Table::Aircraft->value . ' ac ON ac.code = f.aircraft'
            . ' WHERE f.airline = ? AND f.departure_time >= NOW()'
            . ' AND f.departure_time < NOW() + INTERVAL ' . self::WINDOW_DAYS . ' DAY'
            . ' GROUP BY ac.title, ac.manufacturer, ac.is_widebody'
            . ' ORDER BY flights DESC, ac.title ASC'
            . ' LIMIT ' . max(1, $limit),
            [strtoupper($code)],
        );
    }

    /**
     * Who else flies out of this airline's hubs.
     *
     * The one "related airlines" rule in this data that is neither random nor
     * the same list every time. Sharing a country was the obvious alternative
     * and does not work: 45 of the 105 are the only airline we sell from
     * theirs, so the block would be missing from half the pages.
     *
     * Hubs give it geography instead, and the geography reads true -- Air
     * Canada gets WestJet, Air France gets easyJet and Lufthansa, Philippine
     * Airlines gets Cebu Pacific. Measured: all 105 have at least one, and 104
     * of the 105 lists are distinct.
     *
     * Counted over the same WINDOW_DAYS the aircraft list uses, and for the
     * same reason.
     *
     * @param list<string> $hubs
     * @return list<array<string, mixed>>
     */
    public function peers(string $code, array $hubs, int $limit): array
    {
        if ($hubs === []) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT al.code, al.title AS name, COUNT(*) AS flights'
            . ' FROM ' . Table::Flights->value . ' f'
            . ' JOIN ' . Table::Airlines->value . ' al ON al.code = f.airline AND' . self::ONLY_SELLABLE
            . ' WHERE f.departure_airport IN (' . self::placeholders($hubs) . ')'
            . ' AND f.departure_time >= NOW()'
            . ' AND f.departure_time < NOW() + INTERVAL ' . self::WINDOW_DAYS . ' DAY'
            . ' AND al.code <> ?'
            . ' GROUP BY al.code, al.title'
            . ' ORDER BY flights DESC, al.title ASC'
            . ' LIMIT ' . max(1, $limit),
            [...$hubs, strtoupper($code)],
        );
    }

    /** @param list<string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Every airline we sell a seat on, name-ordered for the directory.
     *
     * @return list<array<string, mixed>>
     */
    public function sellable(): array
    {
        return $this->connection->fetchAll(
            'SELECT al.code, al.title AS name, c.title AS country'
            . ' FROM ' . Table::Airlines->value . ' al'
            . ' LEFT JOIN ' . Table::Countries->value . ' c ON c.code = al.country'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' ORDER BY name ASC',
        );
    }

    public function countSellable(): int
    {
        return (int) $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::Airlines->value . ' al WHERE' . self::ONLY_SELLABLE,
        );
    }

    /**
     * The airport codes an airline is based at.
     *
     * The column is a space-separated list -- "YYZ YUL YVR" -- because it is
     * seed data written to be read rather than a join table. Split here, so
     * the one place that knows the format is the one that selects it.
     *
     * Every code listed for every airline we sell is a sellable airport, so
     * nothing here needs to allow for a hub with no airport behind it. Checked
     * across all 105 rather than assumed.
     *
     * @return list<string>
     */
    public static function hubCodes(string $hubs): array
    {
        return preg_split('/\s+/', trim($hubs), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Airlines ordered by title, optionally filtered to specific IATA codes
     * and/or to major carriers only.
     *
     * @param list<string>|null $codes
     * @return list<array<string, mixed>>
     */
    public function search(?array $codes, bool $majorOnly): array
    {
        $sql = 'SELECT * FROM ' . Table::Airlines->value;
        $conditions = [];
        $params = [];

        if ($codes !== null && $codes !== []) {
            $placeholders = implode(', ', array_fill(0, count($codes), '?'));
            $conditions[] = "code IN ($placeholders)";
            $params = array_merge($params, array_values($codes));
        }

        if ($majorOnly) {
            $conditions[] = 'is_major = 1';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY title ASC';

        return $this->connection->fetchAll($sql, $params);
    }

    /**
     * Bump the booking counter for the given airline IATA codes.
     */
    public function recordBooking(string ...$codes): void
    {
        $in = implode(', ', array_fill(0, count($codes), '?'));

        $this->connection->execute(
            'UPDATE ' . Table::Airlines->value
            . ' SET book_count = book_count + 1, last_search = NOW()'
            . " WHERE code IN ($in)",
            array_values($codes),
        );
    }

    /**
     * The airlines people book here, for the footer's column.
     *
     * `book_count` is written by recordBooking() every time a booking is made,
     * so this is demand and not a list somebody keeps up to date -- the same
     * reason the Cities and Directions columns are counted.
     *
     * Two things it needs that the version before it did not have. It is
     * filtered to airlines we sell, because `SELECT *` over the table would
     * happily rank one with no page and put a 404 in the footer of every page
     * on the site -- the bug FooterRenderTest was written for. And `traffic`
     * breaks the tie beneath the count, because a database nobody has booked
     * on yet has 105 airlines on nought and would otherwise order them by
     * whatever the table hands back: with the curated tier behind it the column
     * still opens on the carriers worth naming.
     *
     * @return list<array<string, mixed>>
     */
    public function mostBooked(int $limit): array
    {
        return $this->connection->fetchAll(
            'SELECT al.code, al.title AS name'
            . ' FROM ' . Table::Airlines->value . ' al'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' ORDER BY al.book_count DESC, al.traffic DESC, al.title ASC'
            . ' LIMIT ' . max(1, $limit),
        );
    }
}
