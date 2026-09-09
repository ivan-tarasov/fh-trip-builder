<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Countries, which are a table -- but a thin one.
 *
 * `countries` holds a code, an ISO-3 code and a name, and that is all. Anything
 * else a country page says has to be counted from the airports in it: how many
 * cities we sell to, how many airports serve them, and how far the clocks
 * spread. That is why everything but all() joins -- and all() is the one caller
 * that wants the table on its own, because a billing address is not a flight.
 *
 * Two facts the reference shows are simply not here. Its Canada page names a
 * currency and a visa requirement, and this schema has neither -- both would be
 * new seed data rather than a new query.
 */
final readonly class CountryRepository
{
    /** The same filter the rest of the site sells by. */
    private const string ONLY_SELLABLE = ' a.enabled = 1 AND a.is_major = 1';

    public function __construct(private Connection $connection) {}

    /**
     * One country, with what its airports say about it.
     *
     * The timezone comes back as a span rather than a single offset, because
     * for the countries where it matters a single offset is wrong: the United
     * States runs from GMT-10 to GMT-4 in this data and Australia from +8 to
     * +11. The reference shows one value for Canada and is wrong for the same
     * reason.
     *
     * @return array<string, mixed>|null
     */
    public function byCode(string $code): ?array
    {
        return $this->connection->fetchOne(
            'SELECT c.code, c.code_iso_3, c.title AS name,'
            . ' COUNT(DISTINCT a.city_code) AS cities,'
            . ' COUNT(*) AS airports,'
            . ' MIN(a.timezone) AS timezone_min, MAX(a.timezone) AS timezone_max'
            . ' FROM ' . Table::Countries->value . ' c'
            . ' JOIN ' . Table::Airports->value . ' a ON a.country_code = c.code'
            . ' WHERE' . self::ONLY_SELLABLE . ' AND c.code = ?'
            . ' GROUP BY c.code, c.code_iso_3, c.title',
            [strtoupper($code)],
        );
    }

    /**
     * Every country, code to name, in alphabetical order by name.
     *
     * The billing-country select used to carry six countries written into the
     * template, which is both a short list and data living in a view. The table
     * behind this is already seeded with all of them and is what the airports
     * join against, so a card registered anywhere the app sells flights to can
     * now be entered.
     *
     * All 255 and not only the ones we fly to -- see sellable() for those. A
     * billing address is where the card lives, which has nothing to do with
     * where the flight goes.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT code, title FROM ' . Table::Countries->value . ' ORDER BY title ASC',
        );

        $countries = [];

        foreach ($rows as $row) {
            $countries[(string) $row['code']] = (string) $row['title'];
        }

        return $countries;
    }

    /**
     * Every country we sell a seat to, name-ordered for the directory.
     *
     * The join is what makes it "we sell to" rather than "exists": the seed has
     * 255 countries and we fly to 93 of them, and a directory listing the other
     * 162 would be 162 links to a 404.
     *
     * @return list<array<string, mixed>>
     */
    public function sellable(): array
    {
        return $this->connection->fetchAll(
            'SELECT c.code, c.title AS name, COUNT(DISTINCT a.city_code) AS cities'
            . ' FROM ' . Table::Countries->value . ' c'
            . ' JOIN ' . Table::Airports->value . ' a ON a.country_code = c.code'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' GROUP BY c.code, c.title'
            . ' ORDER BY name ASC',
        );
    }

    /** Counts what sellable() lists, so the join that excludes empty countries stays. */
    public function countSellable(): int
    {
        return (int) $this->connection->fetchValue(
            'SELECT COUNT(DISTINCT c.code)'
            . ' FROM ' . Table::Countries->value . ' c'
            . ' JOIN ' . Table::Airports->value . ' a ON a.country_code = c.code'
            . ' WHERE' . self::ONLY_SELLABLE,
        );
    }

    /**
     * The countries whose airports people search for, busiest first.
     *
     * Nothing here records a search against a country, so this is derived --
     * and how it is derived is the whole decision. The obvious rule is to sum
     * the searches of a country's airports, and the obvious rule is wrong: it
     * ranks a country by how many airports we happen to sell there, so a
     * country with eight quiet ones outranks a country with one busy one. That
     * objection is why this column was curated before there was an answer to
     * it.
     *
     * MAX is the answer. A country is as searched-for as the airport people
     * actually search for, which makes the United Kingdom first on Heathrow
     * alone and lets Morocco in on Casablanca -- both true statements about
     * demand rather than about the size of our catalogue.
     *
     * `traffic_weight` behind it for the same reason the airlines column has
     * `traffic` behind its count: on a database nobody has searched yet, every
     * country is on nought and the curated weight is what keeps the column
     * sensible.
     *
     * @return list<array<string, mixed>>
     */
    public function mostSearched(int $limit): array
    {
        return $this->connection->fetchAll(
            'SELECT c.code, c.title AS name, MAX(a.search_count) AS hits,'
            . ' MAX(a.traffic_weight) AS weight'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' JOIN ' . Table::Countries->value . ' c ON c.code = a.country_code'
            . ' WHERE' . self::ONLY_SELLABLE
            . ' GROUP BY c.code, c.title'
            // Ties broken by name, so the column is the same between renders
            // rather than reshuffling whenever two countries are level.
            . ' ORDER BY hits DESC, weight DESC, c.title ASC'
            . ' LIMIT ' . max(1, $limit),
        );
    }

    /**
     * The country's airports, largest first.
     *
     * Whole rows rather than codes, because the page needs both: the fare query
     * has to name the codes and the map has to plot the coordinates. One query
     * for the two, since 32 rows is the largest answer here -- the United
     * States -- and asking twice would be the more expensive of the two.
     *
     * @return list<array<string, mixed>>
     */
    public function airports(string $code): array
    {
        return $this->connection->fetchAll(
            'SELECT a.code, a.title, a.city, a.city_code, a.latitude, a.longitude'
            . ' FROM ' . Table::Airports->value . ' a'
            . ' WHERE' . self::ONLY_SELLABLE . ' AND a.country_code = ?'
            . ' ORDER BY a.traffic_weight DESC, a.title ASC',
            [strtoupper($code)],
        );
    }
}
