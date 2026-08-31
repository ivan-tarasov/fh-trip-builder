<?php

declare(strict_types=1);

namespace TripBuilder\Service;

use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\Database\Connection;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\TripType;

/**
 * Flight search + shaping, independent of any transport.
 *
 * Each result item carries an `outbound` itinerary and (for round-trips) a
 * `returning` itinerary. An itinerary is an ordered list of `segments` (one per
 * flight leg) plus its `stops` count, `total_duration`, and `layovers` (the
 * connection airport and wait between consecutive segments) — so a direct
 * flight is a one-segment itinerary and a connection has two or more.
 *
 * The HTTP endpoint (Api\Flights\Response) and the page controllers both call
 * this directly, so a page render no longer makes an HTTP request to the app's
 * own API.
 */
final readonly class FlightFinder
{
    private const int PER_PAGE_LIMIT = 10;

    private const string RESPONSE_FLIGHT_ID = 'id';
    private const string RESPONSE_FLIGHTS = 'flights';
    private const string RESPONSE_ITINERARY = 'itinerary';
    private const string RESPONSE_SEGMENTS = 'segments';
    private const string RESPONSE_BADGES = 'badges';
    private const string RESPONSE_STOPS = 'stops';
    private const string RESPONSE_TOTAL_DURATION = 'total_duration';
    private const string RESPONSE_LAYOVERS = 'layovers';
    private const string RESPONSE_WAIT_MINUTES = 'wait_minutes';
    private const string RESPONSE_DEPART = 'depart';
    private const string RESPONSE_ARRIVE = 'arrive';
    private const string RESPONSE_FLIGHT_NUMBER = 'number';
    private const string RESPONSE_AIRPORT_CODE = 'airport_code';
    private const string RESPONSE_AIRPORT_NAME = 'airport_name';
    private const string RESPONSE_AIRPORT_COUNTRY = 'airport_country';
    private const string RESPONSE_AIRPORT_CITY = 'airport_city';
    private const string RESPONSE_DATE_TIME = 'date_time';
    private const string RESPONSE_FLIGHT_CARRIER_CODE = 'carrier';
    private const string RESPONSE_FLIGHT_CARRIER_NAME = 'carrier_name';
    private const string RESPONSE_CABIN_CODE = 'cabin_code';
    private const string RESPONSE_DISTANCE = 'distance';
    private const string RESPONSE_DURATION = 'duration';
    private const string RESPONSE_PRICE_BASE = 'price_base';
    private const string RESPONSE_PRICE_TAX = 'price_tax';
    private const string RESPONSE_RATING = 'rating';

    private const string CABIN_OUTBOUND = 'Y'; // FIXME: we need to add the real one in DB
    private const string CABIN_RETURN = 'X'; // FIXME: we need to add the real one in DB

    public function __construct(private Connection $connection) {}

    /**
     * Run a flight search and shape the paginated result payload.
     *
     * A round trip is chosen one direction at a time rather than as a pairing of
     * every outbound with every return: with no selection this returns the
     * outbound options priced on their own; given the chosen outbound's leg ids
     * it returns the return options priced as the real trip total. A one-way
     * search is a single list of totals.
     *
     * Once both directions are chosen the search stops listing options and
     * returns the assembled round-trip package instead, ready to confirm.
     *
     * @param list<int> $outboundIds leg ids of an already-chosen outbound
     * @param list<int> $returnIds leg ids of an already-chosen return
     * @return array<string, mixed>
     */
    public function search(FlightSearchQuery $query, TripType $tripType, array $outboundIds = [], array $returnIds = []): array
    {
        // Updating search stats
        new AirportRepository($this->connection)->recordSearch($query->from, $query->to);

        $airports = new AirportRepository($this->connection);
        $cities = [
            self::RESPONSE_DEPART => sprintf('%s (%s)', $airports->cityByCode($query->from), $query->from),
            self::RESPONSE_ARRIVE => sprintf('%s (%s)', $airports->cityByCode($query->to), $query->to),
        ];

        $flights = new FlightRepository($this->connection);

        $isRoundtrip = $tripType === TripType::Roundtrip;

        // A stale or tampered selection resolves to null and simply drops the
        // traveller back to the step that still needs a choice.
        $outbound = $isRoundtrip && $outboundIds !== [] ? $flights->itineraryByIds($outboundIds) : null;
        $return = $outbound !== null && $returnIds !== [] ? $flights->itineraryByIds($returnIds) : null;

        $result = ['rows' => [], 'total' => 0, 'cheapest' => null, 'available' => []];
        $addBase = 0.0;
        $addTax = 0.0;

        if ($return !== null) {
            // Step 3: both halves chosen — the package, with nothing left to list.
            $step = 3;
        } elseif ($outbound !== null) {
            // Step 2: the return leg, priced as the real total with the choice.
            $step = 2;
            $result = $flights->searchDirection(
                $query->to,
                $query->from,
                $query->returnDate,
                SortMethod::fromRequest($query->sort),
                $query->currentPage,
                $query->filters,
            );
            $addBase = (float) $outbound['price_base'];
            $addTax = (float) $outbound['price_tax'];
        } else {
            // Step 1 (round trip) or the whole search (one way): the outbound.
            $step = $isRoundtrip ? 1 : null;
            $result = $flights->searchDirection(
                $query->from,
                $query->to,
                $query->departDate,
                SortMethod::fromRequest($query->sort),
                $query->currentPage,
                $query->filters,
            );

            // Round trips show what the whole trip would cost with the cheapest
            // return, so step 1 prices are comparable with the totals at step 2.
            $addBase = ($step === 1
                ? $flights->cheapestTotal($query->to, $query->from, $query->returnDate)
                : null) ?? 0.0;
        }

        $rows = array_map(fn(array $itinerary): array => [
            self::RESPONSE_PRICE_BASE => (float) $itinerary['price_base'] + $addBase,
            self::RESPONSE_PRICE_TAX => round((float) $itinerary['price_tax'] + $addTax, 2),
            self::RESPONSE_ITINERARY => $this->mapItinerary(
                $itinerary,
                $step === 2 ? self::CABIN_RETURN : self::CABIN_OUTBOUND,
            ),
        ], $result['rows']);

        $packagePrice = $return === null ? null : round(
            (float) $outbound['price_base'] + (float) $outbound['price_tax']
            + (float) $return['price_base'] + (float) $return['price_tax'],
            2,
        );

        return [
            'current_page' => $query->currentPage,
            'total_pages' => (int) ceil($result['total'] / self::PER_PAGE_LIMIT),
            'per_page' => self::PER_PAGE_LIMIT,
            'total_flights' => $result['total'],
            // The lowest total on offer, in the same money the rows show, so a
            // row can say how much more than the best option it costs.
            'cheapest_total' => $result['cheapest'] === null
                ? null
                : round($result['cheapest'] + $addBase + $addTax, 2),
            'trip_type' => $tripType->value,
            'step' => $step,
            // Step 1 totals assume the cheapest return, so they are a floor;
            // everywhere else the price is exact for the trip being booked.
            'price_mode' => $step === 1 ? 'from' : 'total',
            'selected' => $outbound === null ? null : $this->mapItinerary($outbound, self::CABIN_OUTBOUND),
            'selected_price' => $outbound === null ? null : round((float) $outbound['price_base'] + (float) $outbound['price_tax'], 2),
            'selected_ids' => $outbound === null ? [] : $outboundIds,
            'selected_return' => $return === null ? null : $this->mapItinerary($return, self::CABIN_RETURN),
            'selected_return_price' => $return === null ? null : round((float) $return['price_base'] + (float) $return['price_tax'], 2),
            'selected_return_ids' => $return === null ? [] : $returnIds,
            'package_price' => $packagePrice,
            self::RESPONSE_DEPART => $cities[self::RESPONSE_DEPART],
            self::RESPONSE_ARRIVE => $cities[self::RESPONSE_ARRIVE],
            'adult_count' => $query->adultNum,
            'child_count' => $query->childNum,
            // Which filter options would still return something, so the sidebar
            // can grey out the ones that would empty the page.
            'available' => $result['available'],
            self::RESPONSE_FLIGHTS => $rows,
        ];
    }

    /**
     * Shape an ordered list of leg ids into booking segments, each carrying its
     * own price so a booking total can be summed. Records the booking stat for
     * the carriers involved. Returns [] when no ids resolve.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findSegments(array $ids): array
    {
        $legs = new FlightRepository($this->connection)->legsByIds($ids);

        if ($legs === []) {
            return [];
        }

        $carriers = array_values(array_unique(array_map(
            static fn(array $leg): string => (string) $leg['carrier'],
            $legs,
        )));

        new AirlineRepository($this->connection)->recordBooking(...$carriers);

        return array_map(fn(array $leg): array => $this->mapLeg($leg, self::CABIN_OUTBOUND) + [
            self::RESPONSE_PRICE_BASE => (float) $leg['price_base'],
            self::RESPONSE_PRICE_TAX => (float) $leg['price_tax'],
        ], $legs);
    }

    /**
     * Rebuild one saved itinerary from its ordered leg ids, shaped like a search
     * result so the same card renders it. Returns null when the ids no longer
     * resolve to a connected itinerary — a saved flight can go stale, and the
     * saved list drops those rather than rendering a broken card.
     *
     * @param list<int> $ids
     * @return array<string, mixed>|null
     */
    public function itinerary(array $ids): ?array
    {
        $itinerary = new FlightRepository($this->connection)->itineraryByIds($ids);

        if ($itinerary === null) {
            return null;
        }

        return [
            self::RESPONSE_ITINERARY => $this->mapItinerary($itinerary, self::CABIN_OUTBOUND),
            self::RESPONSE_PRICE_BASE => (float) $itinerary['price_base'],
            self::RESPONSE_PRICE_TAX => (float) $itinerary['price_tax'],
        ];
    }

    /**
     * A single shaped flight leg by id (for the add-trip flow), or null.
     *
     * @return array<string, mixed>|null
     */
    public function findOne(int $id): ?array
    {
        return $this->findSegments([$id])[0] ?? null;
    }

    /**
     * Shape a repository itinerary (ordered legs + aggregates) into the response
     * shape: per-segment legs, the stop count, total elapsed duration, and the
     * layover (connection airport + wait) between each pair of segments.
     *
     * @param array<string, mixed> $itinerary
     * @return array<string, mixed>
     */
    private function mapItinerary(array $itinerary, string $cabin): array
    {
        $legs = $itinerary['legs'];

        $layovers = [];

        for ($i = 1; $i < count($legs); $i++) {
            $layovers[] = [
                self::RESPONSE_AIRPORT_CODE => $legs[$i - 1]['arr_code'],
                self::RESPONSE_AIRPORT_NAME => $legs[$i - 1]['arr_name'],
                self::RESPONSE_AIRPORT_CITY => $legs[$i - 1]['arr_city'],
                // A layover is at one airport, so this subtraction is safe (leg
                // stamps are local, and can't be subtracted across timezones).
                self::RESPONSE_WAIT_MINUTES => (int) round(
                    (strtotime((string) $legs[$i]['dep_datetime']) - strtotime((string) $legs[$i - 1]['arr_datetime'])) / 60,
                ),
            ];
        }

        return [
            self::RESPONSE_SEGMENTS => array_map(fn(array $leg): array => $this->mapLeg($leg, $cabin), $legs),
            self::RESPONSE_BADGES => $itinerary['badges'] ?? [],
            self::RESPONSE_STOPS => (int) $itinerary['stops'],
            self::RESPONSE_TOTAL_DURATION => (int) $itinerary['duration'],
            self::RESPONSE_LAYOVERS => $layovers,
        ];
    }

    /**
     * Map one hydrated flight leg (plain columns) into the response segment shape.
     *
     * @param array<string, mixed> $leg
     * @return array<string, mixed>
     */
    private function mapLeg(array $leg, string $cabin): array
    {
        return [
            self::RESPONSE_FLIGHT_ID => $leg['id'],
            self::RESPONSE_FLIGHT_CARRIER_CODE => $leg['carrier'],
            self::RESPONSE_FLIGHT_CARRIER_NAME => $leg['carrier_name'],
            self::RESPONSE_FLIGHT_NUMBER => sprintf('%s-%d', $leg['carrier'], $leg['number']),
            self::RESPONSE_DEPART => [
                self::RESPONSE_AIRPORT_CODE => $leg['dep_code'],
                self::RESPONSE_AIRPORT_NAME => $leg['dep_name'],
                self::RESPONSE_AIRPORT_COUNTRY => $leg['dep_country'],
                self::RESPONSE_AIRPORT_CITY => $leg['dep_city'],
                self::RESPONSE_DATE_TIME => $leg['dep_datetime'],
            ],
            self::RESPONSE_ARRIVE => [
                self::RESPONSE_AIRPORT_CODE => $leg['arr_code'],
                self::RESPONSE_AIRPORT_NAME => $leg['arr_name'],
                self::RESPONSE_AIRPORT_COUNTRY => $leg['arr_country'],
                self::RESPONSE_AIRPORT_CITY => $leg['arr_city'],
                self::RESPONSE_DATE_TIME => $leg['arr_datetime'],
            ],
            self::RESPONSE_CABIN_CODE => $cabin,
            self::RESPONSE_DISTANCE => $leg['distance'],
            self::RESPONSE_DURATION => $leg['duration'],
            self::RESPONSE_RATING => (float) $leg['rating'],
        ];
    }
}
