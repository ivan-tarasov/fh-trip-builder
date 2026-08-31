<?php

declare(strict_types=1);

namespace TripBuilder\Api\Flights;

use TripBuilder\Config;

/**
 * The filters a visitor has applied to a search, and the rules for deciding
 * whether one candidate itinerary survives them.
 *
 * Every filter is off by default, so a bare instance matches everything. The
 * predicates read only the columns `FlightRepository::candidateSql()` puts on a
 * candidate row (plus the layover countries the repository resolves before
 * filtering) — nothing here touches the database.
 *
 * `matches()` can skip one dimension by name. That is what availability is
 * built from: the options worth offering for a dimension are the values found
 * among the itineraries that pass every *other* filter, so choosing an airline
 * narrows the layover airports on offer without hiding the other airlines.
 */
final readonly class FlightFilters
{
    // Dimension names, used to lift one filter when measuring availability.
    public const string DIM_STOPS = 'stops';
    public const string DIM_AIRLINES = 'airlines';
    public const string DIM_SINGLE_CARRIER = 'one_airline';
    public const string DIM_PRICE = 'max_price';
    public const string DIM_DURATION = 'max_duration';
    public const string DIM_DEPART_TIME = 'dep_time';
    public const string DIM_ARRIVE_TIME = 'arr_time';
    public const string DIM_ARRIVE_DATE = 'arr_date';
    public const string DIM_LAYOVER_AIRPORTS = 'via';
    public const string DIM_DEPART_AIRPORTS = 'from_ap';
    public const string DIM_ARRIVE_AIRPORTS = 'to_ap';
    public const string DIM_AIRCRAFT = 'aircraft';
    public const string DIM_NO_NIGHT = 'no_night';
    public const string DIM_NO_GULF = 'no_gulf';
    public const string DIM_NO_VISA = 'no_visa';

    /**
     * @param list<int> $stops allowed stop counts; empty means any
     * @param list<string> $airlines allowed carrier codes; empty means any
     * @param array{0: int, 1: int}|null $departWindow minute-of-day range
     * @param array{0: int, 1: int}|null $arriveWindow minute-of-day range
     * @param list<string> $departBuckets time_buckets keys
     * @param list<string> $arriveBuckets time_buckets keys
     * @param list<string> $arriveDates Y-m-d
     * @param list<string> $layoverAirports allowed connection airports
     * @param list<string> $departAirports allowed first-leg departure airports
     * @param list<string> $arriveAirports allowed last-leg arrival airports
     * @param list<string> $aircraft allowed aircraft type codes
     */
    public function __construct(
        public array $stops = [],
        public array $airlines = [],
        public bool $singleCarrier = false,
        public ?float $maxPrice = null,
        public ?int $maxDuration = null,
        public ?array $departWindow = null,
        public ?array $arriveWindow = null,
        public array $departBuckets = [],
        public array $arriveBuckets = [],
        public array $arriveDates = [],
        public array $layoverAirports = [],
        public array $departAirports = [],
        public array $arriveAirports = [],
        public array $aircraft = [],
        public bool $noNightLayover = false,
        public bool $noGulfLayover = false,
        public bool $noVisaLayover = false,
    ) {}

    /**
     * Every query-string key a filter reads. The controller carries these
     * through untouched so pagination, the step links and a shared URL all keep
     * the filtering that produced the page.
     *
     * @var list<string>
     */
    public const array QUERY_KEYS = [
        self::DIM_STOPS,
        self::DIM_AIRLINES,
        self::DIM_SINGLE_CARRIER,
        self::DIM_PRICE,
        self::DIM_DURATION,
        self::DIM_DEPART_TIME,
        self::QUERY_DEPART_BUCKETS,
        self::DIM_ARRIVE_TIME,
        self::QUERY_ARRIVE_BUCKETS,
        self::DIM_ARRIVE_DATE,
        self::DIM_LAYOVER_AIRPORTS,
        self::DIM_DEPART_AIRPORTS,
        self::DIM_ARRIVE_AIRPORTS,
        self::DIM_AIRCRAFT,
        self::DIM_NO_NIGHT,
        self::DIM_NO_GULF,
        self::DIM_NO_VISA,
    ];

    // The time dimensions take two keys: a set of named parts of the day, and
    // a finer custom range that overrides them.
    public const string QUERY_DEPART_BUCKETS = 'dep_when';
    public const string QUERY_ARRIVE_BUCKETS = 'arr_when';

    /**
     * Prefix marking the return leg's own filter set.
     *
     * A round trip is chosen one direction at a time and the two rarely want
     * the same constraints — an airline that flies out may not fly back, and a
     * morning departure has nothing to say about the flight home. Each leg
     * therefore carries its own filters, and both sets ride in the URL so
     * stepping back to a leg finds the filters it had.
     */
    public const string RETURN_PREFIX = 'return_';

    /**
     * Read filters out of a query string.
     *
     * Everything here is visitor-supplied, so each value is checked against the
     * shape it should have and dropped otherwise. Nothing reaches SQL — filters
     * run in PHP — but a malformed value that survived would quietly filter the
     * whole search away, which reads as "no flights" rather than "bad link".
     *
     * @param array<string, mixed> $query
     * @param string $prefix RETURN_PREFIX to read the return leg's set
     */
    public static function fromQuery(array $query, string $prefix = ''): self
    {
        // Read every key through the prefix, so one leg never sees the other's.
        $query = self::forPrefix($query, $prefix);

        $csv = static function (mixed $raw, string $pattern, int $limit = 100): array {
            $values = array_filter(
                self::values($raw),
                static fn(string $v): bool => preg_match($pattern, $v) === 1,
            );

            return array_values(array_slice(array_unique($values), 0, $limit));
        };

        $flag = static fn(mixed $raw): bool => $raw === '1' || $raw === 1 || $raw === true;

        $positive = static function (mixed $raw): ?float {
            if (!is_numeric($raw)) {
                return null;
            }

            return (float) $raw > 0 ? (float) $raw : null;
        };

        $buckets = static function (mixed $raw): array {
            /** @var array<string, mixed> $known */
            $known = (array) Config::get('search.filters.time_buckets', []);

            return array_values(array_filter(
                array_map(strtolower(...), self::values($raw)),
                static fn(string $key): bool => isset($known[$key]),
            ));
        };

        $duration = $positive($query[self::DIM_DURATION] ?? null);

        return new self(
            stops: array_values(array_filter(
                array_map(intval(...), $csv($query[self::DIM_STOPS] ?? null, '/^[0-9]$/')),
                static fn(int $n): bool => $n >= 0 && $n <= 9,
            )),
            airlines: $csv($query[self::DIM_AIRLINES] ?? null, '/^[A-Z0-9]{2}$/'),
            singleCarrier: $flag($query[self::DIM_SINGLE_CARRIER] ?? null),
            maxPrice: $positive($query[self::DIM_PRICE] ?? null),
            maxDuration: $duration === null ? null : (int) $duration,
            departWindow: self::window($query[self::DIM_DEPART_TIME] ?? null),
            arriveWindow: self::window($query[self::DIM_ARRIVE_TIME] ?? null),
            departBuckets: $buckets($query[self::QUERY_DEPART_BUCKETS] ?? null),
            arriveBuckets: $buckets($query[self::QUERY_ARRIVE_BUCKETS] ?? null),
            arriveDates: self::dates($query[self::DIM_ARRIVE_DATE] ?? null),
            layoverAirports: $csv($query[self::DIM_LAYOVER_AIRPORTS] ?? null, '/^[A-Z]{3}$/'),
            departAirports: $csv($query[self::DIM_DEPART_AIRPORTS] ?? null, '/^[A-Z]{3}$/'),
            arriveAirports: $csv($query[self::DIM_ARRIVE_AIRPORTS] ?? null, '/^[A-Z]{3}$/'),
            aircraft: $csv($query[self::DIM_AIRCRAFT] ?? null, '/^[A-Z0-9]{3}$/'),
            noNightLayover: $flag($query[self::DIM_NO_NIGHT] ?? null),
            noGulfLayover: $flag($query[self::DIM_NO_GULF] ?? null),
            noVisaLayover: $flag($query[self::DIM_NO_VISA] ?? null),
        );
    }

    /**
     * The subset of a query belonging to one leg, with the prefix stripped so
     * the parser can work in plain key names.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private static function forPrefix(array $query, string $prefix): array
    {
        if ($prefix === '') {
            return $query;
        }

        $own = [];

        foreach (self::QUERY_KEYS as $key) {
            if (array_key_exists($prefix . $key, $query)) {
                $own[$key] = $query[$prefix . $key];
            }
        }

        return $own;
    }

    /**
     * This leg's query keys, prefixed. The controller carries both legs' keys
     * through every URL it builds.
     *
     * @return list<string>
     */
    public static function queryKeys(string $prefix = ''): array
    {
        return array_map(static fn(string $key): string => $prefix . $key, self::QUERY_KEYS);
    }

    /**
     * The values of a multi-valued filter, however they arrived.
     *
     * A checkbox group posts `stops[]=0&stops[]=1`; a hand-written or shared
     * link is more likely to say `stops=0,1`. Both are read the same way, so a
     * URL stays writable by hand without the form having to build one.
     *
     * @return list<string>
     */
    public static function values(mixed $raw): array
    {
        if (is_array($raw)) {
            $raw = implode(',', array_filter($raw, static fn(mixed $v): bool => is_string($v) || is_int($v)));
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', strtoupper($raw))),
            static fn(string $v): bool => $v !== '',
        ));
    }

    /**
     * A "from-to" minute-of-day range, or null when it is missing, malformed,
     * or covers the whole day (in which case it constrains nothing).
     *
     * @return array{0: int, 1: int}|null
     */
    private static function window(mixed $raw): ?array
    {
        if (!is_string($raw) || preg_match('/^(\d{1,4})-(\d{1,4})$/', $raw, $match) !== 1) {
            return null;
        }

        $from = (int) $match[1];
        $to = (int) $match[2];

        if ($from > $to || $to > 1440 || ($from === 0 && $to >= 1439)) {
            return null;
        }

        return [$from, $to];
    }

    /**
     * @return list<string>
     */
    private static function dates(mixed $raw): array
    {
        return array_values(array_filter(
            self::values($raw),
            // Real calendar dates only: date() would happily echo back 2026-13-45.
            static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
                && date('Y-m-d', (int) strtotime($d)) === $d,
        ));
    }

    /**
     * Whether anything is filtered at all — lets the search skip the work.
     */
    public function isEmpty(): bool
    {
        return $this->stops === []
            && $this->airlines === []
            && !$this->singleCarrier
            && $this->maxPrice === null
            && $this->maxDuration === null
            && $this->departWindow === null
            && $this->arriveWindow === null
            && $this->departBuckets === []
            && $this->arriveBuckets === []
            && $this->arriveDates === []
            && $this->layoverAirports === []
            && $this->departAirports === []
            && $this->arriveAirports === []
            && $this->aircraft === []
            && !$this->noNightLayover
            && !$this->noGulfLayover
            && !$this->noVisaLayover;
    }

    /**
     * How many filters are set, counting each control once.
     *
     * Used to tell a visitor standing on one leg of a round trip that the other
     * leg is narrowed — otherwise the only clue is the query string.
     */
    public function appliedCount(): int
    {
        $set = [
            $this->stops !== [],
            $this->airlines !== [],
            $this->singleCarrier,
            $this->maxPrice !== null,
            $this->maxDuration !== null,
            $this->departWindow !== null || $this->departBuckets !== [],
            $this->arriveWindow !== null || $this->arriveBuckets !== [],
            $this->arriveDates !== [],
            $this->layoverAirports !== [],
            $this->departAirports !== [],
            $this->arriveAirports !== [],
            $this->aircraft !== [],
            $this->noNightLayover,
            $this->noGulfLayover,
            $this->noVisaLayover,
        ];

        return count(array_filter($set));
    }

    /**
     * Whether any active filter needs to know which country a layover is in.
     * Resolving that costs a lookup, so the search only pays it when asked.
     */
    public function needsLayoverCountries(): bool
    {
        return $this->noGulfLayover || $this->noVisaLayover;
    }

    /**
     * Does this candidate itinerary survive every filter but `$except`?
     *
     * @param array<string, mixed> $candidate a row from candidateSql, plus
     *        `stop_countries` / `origin_country` / `destination_country` when
     *        a country-aware filter is active
     */
    public function matches(array $candidate, ?string $except = null): bool
    {
        foreach ($this->predicates() as $dimension => $predicate) {
            if ($dimension !== $except && !$predicate($candidate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One predicate per dimension. Each returns true when the filter is off, so
     * an unset filter never excludes anything.
     *
     * @return array<string, callable(array<string, mixed>): bool>
     */
    private function predicates(): array
    {
        return [
            self::DIM_STOPS => fn(array $c): bool => $this->stops === []
                || in_array((int) $c['stops'], $this->stops, true),

            // Flying a leg is enough. Requiring every leg reads better in
            // principle — pick an airline, fly that airline — but almost no
            // generated itinerary is single-carrier, so it left the filter
            // offering nothing on most searches. Use the "All flights with one
            // airline" toggle alongside it to demand the whole trip.
            self::DIM_AIRLINES => fn(array $c): bool => $this->airlines === []
                || array_intersect($this->listOf($c, 'carriers'), $this->airlines) !== [],

            self::DIM_SINGLE_CARRIER => fn(array $c): bool => !$this->singleCarrier
                || count(array_unique($this->listOf($c, 'carriers'))) <= 1,

            // `price_offset` is whatever the other half of a round trip adds
            // before this row is shown; without it a limit set against the
            // displayed total would be compared to one leg's share of it.
            self::DIM_PRICE => fn(array $c): bool => $this->maxPrice === null
                || (float) $c['price_base'] + (float) $c['price_tax']
                    + (float) ($c['price_offset'] ?? 0) <= $this->maxPrice,

            self::DIM_DURATION => fn(array $c): bool => $this->maxDuration === null
                || (int) $c['duration'] <= $this->maxDuration,

            self::DIM_DEPART_TIME => fn(array $c): bool => $this->inTimeOfDay(
                (string) $c['depart_time'],
                $this->departWindow,
                $this->departBuckets,
            ),

            self::DIM_ARRIVE_TIME => fn(array $c): bool => $this->inTimeOfDay(
                (string) $c['arrive_time'],
                $this->arriveWindow,
                $this->arriveBuckets,
            ),

            self::DIM_ARRIVE_DATE => fn(array $c): bool => $this->arriveDates === []
                || in_array(date('Y-m-d', (int) strtotime((string) $c['arrive_time'])), $this->arriveDates, true),

            // Connecting anywhere chosen is enough. Requiring *every*
            // connection to be chosen is the stricter reading, and it made the
            // filter almost unusable: on a route served mostly by two-stop
            // itineraries no single airport could unlock anything, so the list
            // offered one airport out of eleven on the cards. A direct flight
            // connects nowhere, so it is not "a trip through Hong Kong".
            self::DIM_LAYOVER_AIRPORTS => fn(array $c): bool => $this->layoverAirports === []
                || array_intersect($this->listOf($c, 'stops_at'), $this->layoverAirports) !== [],

            self::DIM_DEPART_AIRPORTS => fn(array $c): bool => $this->departAirports === []
                || in_array((string) $c['dep_airport'], $this->departAirports, true),

            self::DIM_ARRIVE_AIRPORTS => fn(array $c): bool => $this->arriveAirports === []
                || in_array((string) $c['arr_airport'], $this->arriveAirports, true),

            // Any leg on a chosen type is enough — you are picking a plane you
            // want to fly on, not demanding the whole trip use one.
            self::DIM_AIRCRAFT => fn(array $c): bool => $this->aircraft === []
                || array_intersect($this->listOf($c, 'aircraft'), $this->aircraft) !== [],

            self::DIM_NO_NIGHT => fn(array $c): bool => !$this->noNightLayover
                || !$this->hasNightLayover($c),

            self::DIM_NO_GULF => fn(array $c): bool => !$this->noGulfLayover
                || array_intersect(
                    $this->listOf($c, 'stop_countries'),
                    (array) Config::get('search.filters.gulf_countries', []),
                ) === [],

            self::DIM_NO_VISA => fn(array $c): bool => !$this->noVisaLayover
                || $this->transitCountries($c) === [],
        ];
    }

    /**
     * A stamp falls inside the chosen part of the day. An explicit window wins
     * over the buckets: the slider is the finer control, so when it has been
     * moved it is what the visitor meant.
     *
     * @param array{0: int, 1: int}|null $window
     * @param list<string> $buckets
     */
    private function inTimeOfDay(string $stamp, ?array $window, array $buckets): bool
    {
        if ($window === null && $buckets === []) {
            return true;
        }

        $at = (int) date('G', (int) strtotime($stamp)) * 60 + (int) date('i', (int) strtotime($stamp));

        if ($window !== null) {
            return $at >= $window[0] && $at <= $window[1];
        }

        foreach ($buckets as $bucket) {
            $range = Config::get('search.filters.time_buckets.' . $bucket);

            // Half-open, so a stamp belongs to exactly one bucket.
            if (is_array($range) && $at >= (int) $range['from'] && $at < (int) $range['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any connection on this itinerary is spent waiting through the
     * night. The wait is at one airport, so its local stamps compare directly.
     *
     * @param array<string, mixed> $candidate
     */
    private function hasNightLayover(array $candidate): bool
    {
        foreach ([['stop1_in', 'stop1_out'], ['stop2_in', 'stop2_out']] as [$in, $out]) {
            if (($candidate[$in] ?? null) === null || ($candidate[$out] ?? null) === null) {
                continue;
            }

            if ($this->spansNight((string) $candidate[$in], (string) $candidate[$out])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a wait overlaps the small hours on any night it touches. Mirrors
     * the rule behind the "Night layover" notice on a flight card.
     */
    private function spansNight(string $from, string $to): bool
    {
        $start = (int) strtotime($from);
        $end = (int) strtotime($to);
        $nightFrom = (int) Config::get('search.filters.night_from_hour', 23);
        $nightTo = (int) Config::get('search.filters.night_to_hour', 6);
        $day = 86400;

        for ($midnight = (int) strtotime('midnight', $start) - $day; $midnight <= $end; $midnight += $day) {
            if ($start < $midnight + $day + $nightTo * 3600 && $end > $midnight + $nightFrom * 3600) {
                return true;
            }
        }

        return false;
    }

    /**
     * Layover countries that are neither the origin's nor the destination's —
     * the ones a traveller may need a transit visa for.
     *
     * @param array<string, mixed> $candidate
     * @return list<string>
     */
    private function transitCountries(array $candidate): array
    {
        $endpoints = array_filter([
            $candidate['origin_country'] ?? null,
            $candidate['destination_country'] ?? null,
        ]);

        return array_values(array_diff($this->listOf($candidate, 'stop_countries'), $endpoints));
    }

    /**
     * A comma-joined candidate column as a list. The repository builds these
     * with CONCAT_WS, which drops NULLs, so a direct itinerary yields [].
     *
     * @param array<string, mixed> $candidate
     * @return list<string>
     */
    private function listOf(array $candidate, string $column): array
    {
        $raw = $candidate[$column] ?? null;

        if (is_array($raw)) {
            return array_values(array_filter(array_map(strval(...), $raw), static fn(string $v): bool => $v !== ''));
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $raw), static fn(string $v): bool => $v !== ''));
    }
}
