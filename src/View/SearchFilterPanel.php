<?php

declare(strict_types=1);

namespace TripBuilder\View;

use Closure;
use stdClass;
use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Config;
use TripBuilder\Database\Connection;
use TripBuilder\Helper;
use TripBuilder\Repository\AircraftRepository;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;

/**
 * The search sidebar: every filter control, with the codes turned into names.
 *
 * 611 lines of this lived in `SearchController`, which is how that file reached
 * 1,651 (E27, #200). It is view-model construction, and the two siblings in
 * this directory -- `ItineraryPresenter` and `BookingPresenter` -- already do
 * exactly this job for the itinerary and the booking. The sidebar was the one
 * place that skipped the pattern.
 *
 * What it needs is a search response, the query that produced it, a database to
 * turn codes into names with, and a way to build a URL back to this search.
 * None of that is a request, a session or a controller, so the awkward parts --
 * `sliderOption` and `rangeOption`, where an off-by-one shows up as a filter
 * that silently excludes the cheapest flight -- can be reached by a test
 * directly instead of by running a whole search.
 */
final readonly class SearchFilterPanel
{
    // Roughly how many positions a slider handle should have.
    private const int SLIDER_STOPS = 40;

    // Step sizes a slider may round to, smallest first. Money climbs in the
    // usual 1/2.5/5 pattern; time sticks to fractions of an hour, so a handle
    // never stops somewhere like 41h 51m.
    private const array PRICE_STEPS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];
    private const array DURATION_STEPS = [5, 10, 15, 30, 60, 120, 180, 360, 720];

    /** Which leg these controls submit under: empty for the outbound. */
    private string $prefix;

    /**
     * This leg's chosen values, keyed without the prefix. Only this leg's: the
     * other leg's ride along in the URL but must not show up as ticked boxes
     * on this one.
     *
     * @var array<string, string|list<string>|null>
     */
    private array $chosen;

    /**
     * Cheapest itinerary carrying each option, so a row can say what choosing
     * it would cost -- the single thing that turns the sidebar from a set of
     * switches into something you can shop with.
     *
     * @var array<string, array<array-key, float>>
     */
    private array $optionPrices;

    /**
     * @param array<string, mixed> $get the query as it arrived
     * @param Closure(array<string, mixed>): string $link this search with the
     *        given query applied, from the first page. Paging belongs to the
     *        caller: every link built here is a filter change, and a filter
     *        change that kept `shown=210` would hand back a page of results
     *        nobody had scrolled to.
     */
    public function __construct(
        private stdClass $data,
        private array $get,
        private Connection $connection,
        private ItineraryPresenter $presenter,
        private Closure $link,
    ) {
        $this->prefix = FlightFilters::prefixFor($this->data->step ?? null);
        $this->chosen = $this->legFilterQuery($this->prefix);
        $this->optionPrices = (array) json_decode(
            (string) json_encode($this->data->option_prices ?? []),
            true,
        );
    }

    /**
     * Everything the sidebar needs to draw itself: each filter's options with
     * the codes turned into names, which of them are currently chosen, and
     * which would return nothing if chosen.
     *
     * The search reports availability as bare codes because it never joins the
     * airline, airport or aircraft tables — that is what keeps it fast. Those
     * lookups happen here instead, once per render over a handful of rows.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $available = (array) $this->data->available;
        // The response reaches here through json_decode's object mode, so a map
        // of maps arrives as nested stdClass. Availability is a map of lists and
        // survives the cast; bounds needs the round trip.
        $bounds = (array) json_decode((string) json_encode($this->data->bounds), true);

        // array_values, because these index the options as offered and a map
        // with holes in it is not the list the builders below take.
        $codes = static fn(string $dimension): array => array_values(array_map(
            strval(...),
            (array) ($available[$dimension] ?? []),
        ));

        return [
            'stops' => $this->stopOptions($codes(FlightFilters::DIM_STOPS), $this->chosen),
            'airlines' => $this->airlineOptions($codes(FlightFilters::DIM_AIRLINES), $this->chosen),
            'layover_airports' => $this->airportOptions(
                $codes(FlightFilters::DIM_LAYOVER_AIRPORTS),
                FlightFilters::DIM_LAYOVER_AIRPORTS,
                $this->chosen,
            ),
            'depart_airports' => $this->airportOptions(
                $codes(FlightFilters::DIM_DEPART_AIRPORTS),
                FlightFilters::DIM_DEPART_AIRPORTS,
                $this->chosen,
            ),
            'arrive_airports' => $this->airportOptions(
                $codes(FlightFilters::DIM_ARRIVE_AIRPORTS),
                FlightFilters::DIM_ARRIVE_AIRPORTS,
                $this->chosen,
            ),
            'aircraft' => $this->aircraftOptions($codes(FlightFilters::DIM_AIRCRAFT), $this->chosen),
            'arrive_dates' => $this->dateOptions($codes(FlightFilters::DIM_ARRIVE_DATE), $this->chosen),
            'depart_buckets' => $this->bucketOptions(
                $codes(FlightFilters::DIM_DEPART_TIME),
                FlightFilters::QUERY_DEPART_BUCKETS,
                $this->chosen,
            ),
            'arrive_buckets' => $this->bucketOptions(
                $codes(FlightFilters::DIM_ARRIVE_TIME),
                FlightFilters::QUERY_ARRIVE_BUCKETS,
                $this->chosen,
            ),
            // A toggle is available when switching it on would leave something.
            'toggles' => [
                FlightFilters::DIM_SINGLE_CARRIER => [
                    'label' => 'All flights, one airline',
                    'hint' => 'One carrier for the whole trip, so bags are checked through.',
                    'on' => isset($this->chosen[FlightFilters::DIM_SINGLE_CARRIER]),
                    'available' => (bool) ($available[FlightFilters::DIM_SINGLE_CARRIER] ?? false),
                    'price' => $this->optionPrice(FlightFilters::DIM_SINGLE_CARRIER, '1'),
                ],
                FlightFilters::DIM_NO_VISA => [
                    'label' => 'No transit visa',
                    'hint' => 'Hides connections in a country that is neither your origin nor your'
                        . ' destination. Check the requirements yourself before booking.',
                    'on' => isset($this->chosen[FlightFilters::DIM_NO_VISA]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_VISA] ?? false),
                    'price' => $this->optionPrice(FlightFilters::DIM_NO_VISA, '1'),
                ],
                FlightFilters::DIM_NO_GULF => [
                    'label' => 'No layovers in the Gulf',
                    'hint' => 'Hides connections in the United Arab Emirates, Saudi Arabia, Qatar,'
                        . ' Kuwait, Bahrain and Oman.',
                    'on' => isset($this->chosen[FlightFilters::DIM_NO_GULF]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_GULF] ?? false),
                    'price' => $this->optionPrice(FlightFilters::DIM_NO_GULF, '1'),
                ],
                FlightFilters::DIM_NO_NIGHT => [
                    'label' => 'No overnight layovers',
                    'hint' => 'Hides connections spent waiting between 23:00 and 06:00.',
                    'on' => isset($this->chosen[FlightFilters::DIM_NO_NIGHT]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_NIGHT] ?? false),
                    'price' => $this->optionPrice(FlightFilters::DIM_NO_NIGHT, '1'),
                ],
            ],
            'ranges' => [
                FlightFilters::DIM_LAYOVER_RANGE => self::rangeOption(
                    $bounds[FlightFilters::DIM_LAYOVER_RANGE] ?? null,
                    $this->chosen[FlightFilters::DIM_LAYOVER_RANGE] ?? null,
                    self::DURATION_STEPS,
                    'minutes',
                ),
            ],
            'sliders' => [
                FlightFilters::DIM_PRICE => self::sliderOption(
                    $bounds[FlightFilters::DIM_PRICE] ?? null,
                    $this->chosen[FlightFilters::DIM_PRICE] ?? null,
                    self::PRICE_STEPS,
                    'money',
                ),
                FlightFilters::DIM_DURATION => self::sliderOption(
                    $bounds[FlightFilters::DIM_DURATION] ?? null,
                    $this->chosen[FlightFilters::DIM_DURATION] ?? null,
                    self::DURATION_STEPS,
                    'minutes',
                ),
            ],
            // Which groups hold something the visitor has set, so a filter is
            // never left hidden behind a collapsed heading.
            'active' => $this->activeSections($this->chosen),
            // The prefix the controls submit under, so each leg writes its own.
            'prefix' => $this->prefix,
            // Where this leg starts and ends. Reversed on the return, so the
            // time filters name the airports they actually apply to rather than
            // the ones the search was typed with.
            'leg' => [
                'from' => $this->prefix === FlightFilters::RETURN_PREFIX
                    ? (string) $this->data->arrive_city_name
                    : (string) $this->data->depart_city_name,
                'to' => $this->prefix === FlightFilters::RETURN_PREFIX
                    ? (string) $this->data->depart_city_name
                    : (string) $this->data->arrive_city_name,
            ],
            // What the leg you are not looking at is filtered by.
            'other_leg' => $this->otherLegNote($this->prefix),
            // Whether this leg is filtered, so the sidebar can offer a way out.
            'any_applied' => !FlightFilters::fromQuery($this->get, $this->prefix)->isEmpty(),
            'clear_url' => $this->clearFiltersUrl($this->prefix),
        ];
    }

    /**
     * Whether each sidebar group has a filter applied.
     *
     * A collapsed group hides its controls, so one carrying an active filter
     * has to open itself — otherwise the only clue that a search is narrowed is
     * the result count.
     *
     * @param array<string, string|list<string>|null> $chosen
     * @return array<string, bool>
     */
    private function activeSections(array $chosen): array
    {
        // Section id => the query keys it owns.
        $groups = [
            'stops' => [FlightFilters::DIM_STOPS, FlightFilters::DIM_LAYOVER_RANGE],
            'conditions' => [
                FlightFilters::DIM_SINGLE_CARRIER,
                FlightFilters::DIM_NO_VISA,
                FlightFilters::DIM_NO_GULF,
                FlightFilters::DIM_NO_NIGHT,
            ],
            'price' => [FlightFilters::DIM_PRICE],
            'duration' => [FlightFilters::DIM_DURATION],
            'times' => [
                FlightFilters::DIM_DEPART_TIME,
                FlightFilters::QUERY_DEPART_BUCKETS,
                FlightFilters::DIM_ARRIVE_TIME,
                FlightFilters::QUERY_ARRIVE_BUCKETS,
            ],
            'arrdate' => [FlightFilters::DIM_ARRIVE_DATE],
            'airlines' => [FlightFilters::DIM_AIRLINES],
            'via' => [FlightFilters::DIM_LAYOVER_AIRPORTS],
            'fromap' => [FlightFilters::DIM_DEPART_AIRPORTS],
            'toap' => [FlightFilters::DIM_ARRIVE_AIRPORTS],
            'aircraft' => [FlightFilters::DIM_AIRCRAFT],
        ];

        $active = [];

        foreach ($groups as $section => $keys) {
            $active[$section] = false;

            foreach ($keys as $key) {
                if (($this->chosen[$key] ?? null) !== null) {
                    $active[$section] = true;
                    break;
                }
            }
        }

        return $active;
    }

    /**
     * The cheapest total for one option of one dimension, formatted, or null
     * when the search could not price it.
     *
     * @return array{whole: string, cents: string}|null
     */
    private function optionPrice(string $dimension, string $value): ?array
    {
        $price = $this->optionPrices[$dimension][$value] ?? null;

        return is_numeric($price) ? $this->presenter->priceParts((float) $price) : null;
    }

    /**
     * Values chosen for one filter key, from the query as it arrived.
     *
     * @param array<string, string|list<string>|null> $chosen
     * @return list<string>
     */
    private function selected(array $chosen, string $key): array
    {
        return FlightFilters::values($this->chosen[$key] ?? null);
    }

    /**
     * @param list<string> $available
     * @param array<string, string|list<string>|null> $chosen
     * @return list<array<string, mixed>>
     */
    private function stopOptions(array $available, array $chosen): array
    {
        $picked = $this->selected($this->chosen, FlightFilters::DIM_STOPS);
        $options = [];

        // Every level the search can produce, so an unreachable one greys out
        // in place instead of disappearing from the list.
        for ($stops = 0; $stops <= (int) Config::get('search.connections.max_stops', 2); $stops++) {
            $options[] = [
                'value' => (string) $stops,
                'label' => $this->presenter->stopsLabel($stops),
                'sub' => null,
                'price' => $this->optionPrice(FlightFilters::DIM_STOPS, (string) $stops),
                'checked' => in_array((string) $stops, $picked, true),
                'available' => in_array((string) $stops, $available, true),
            ];
        }

        return $options;
    }

    /**
     * @param list<string> $available
     * @param array<string, string|list<string>|null> $chosen
     * @return list<array<string, mixed>>
     */
    private function airlineOptions(array $available, array $chosen): array
    {
        if ($available === []) {
            return [];
        }

        $picked = $this->selected($this->chosen, FlightFilters::DIM_AIRLINES);

        // The lookup returns rows alphabetically; $available is ordered by how
        // many itineraries each carrier flies, which is the order worth showing.
        $titles = [];

        foreach (new AirlineRepository($this->connection)->search($available, false) as $airline) {
            $titles[(string) $airline['code']] = (string) $airline['title'];
        }

        $options = [];

        foreach ($available as $code) {
            $options[] = [
                'value' => $code,
                'label' => $titles[$code] ?? $code,
                'sub' => $code,
                'logo_url' => $this->presenter->carrierLogo($code),
                'price' => $this->optionPrice(FlightFilters::DIM_AIRLINES, $code),
                'checked' => in_array($code, $picked, true),
                'available' => true,
            ];
        }

        return $options;
    }

    /**
     * @param list<string> $available
     * @param array<string, string|list<string>|null> $chosen
     * @return list<array<string, mixed>>
     */
    private function airportOptions(array $available, string $key, array $chosen): array
    {
        if ($available === []) {
            return [];
        }

        $picked = $this->selected($this->chosen, $key);
        $rows = [];

        foreach (new AirportRepository($this->connection)->byCodes($available) as $airport) {
            $rows[(string) $airport['code']] = $airport;
        }

        $options = [];

        // Busiest first, as the availability list came back.
        foreach ($available as $code) {
            $airport = $rows[$code] ?? null;

            $options[] = [
                'value' => $code,
                'label' => $airport === null ? $code : (string) $airport['city'],
                'sub' => $airport === null ? null : trim(sprintf(
                    '%s %s',
                    Helper::airportNameAfterCity((string) $airport['title'], (string) $airport['city']),
                    $code,
                )),
                'note' => $airport === null ? '' : (string) ($airport['country'] ?? ''),
                'price' => $this->optionPrice($key, $code),
                'checked' => in_array($code, $picked, true),
                'available' => true,
            ];
        }

        return $options;
    }

    /**
     * @param list<string> $available
     * @param array<string, string|list<string>|null> $chosen
     * @return list<array<string, mixed>>
     */
    private function aircraftOptions(array $available, array $chosen): array
    {
        if ($available === []) {
            return [];
        }

        $picked = $this->selected($this->chosen, FlightFilters::DIM_AIRCRAFT);
        $types = new AircraftRepository($this->connection)->all();
        $options = [];

        foreach ($available as $code) {
            $options[] = [
                'value' => $code,
                'label' => $types[$code]['title'] ?? $code,
                'sub' => null,
                'price' => $this->optionPrice(FlightFilters::DIM_AIRCRAFT, $code),
                'checked' => in_array($code, $picked, true),
                'available' => true,
            ];
        }

        return $options;
    }

    /**
     * @param list<string> $available
     * @param array<string, string|list<string>|null> $chosen
     * @return list<array<string, mixed>>
     */
    private function dateOptions(array $available, array $chosen): array
    {
        $picked = $this->selected($this->chosen, FlightFilters::DIM_ARRIVE_DATE);
        $options = [];

        sort($available);

        foreach ($available as $date) {
            $options[] = [
                'value' => $date,
                'label' => date('j F, D', (int) strtotime($date)),
                'sub' => null,
                'price' => $this->optionPrice(FlightFilters::DIM_ARRIVE_DATE, $date),
                'checked' => in_array($date, $picked, true),
                'available' => true,
            ];
        }

        return $options;
    }

    /**
     * Parts of the day, always all of them: an empty one greys out rather than
     * vanishing, so the row of pills keeps its shape between searches.
     *
     * @param list<string> $available
     * @param array<string, string|list<string>|null> $chosen
     * @return list<array<string, mixed>>
     */
    private function bucketOptions(array $available, string $key, array $chosen): array
    {
        $picked = array_map(strtolower(...), FlightFilters::values($this->chosen[$key] ?? null));
        $options = [];

        /** @var array<string, array{title: string, icon: string, from: int, to: int}> $buckets */
        $buckets = (array) Config::get('search.filters.time_buckets', []);

        foreach ($buckets as $bucket => $meta) {
            $options[] = [
                'value' => (string) $bucket,
                'label' => (string) $meta['title'],
                'icon' => (string) $meta['icon'],
                'price' => $this->optionPrice($key, (string) $bucket),
                'checked' => in_array((string) $bucket, $picked, true),
                'available' => in_array((string) $bucket, $available, true),
            ];
        }

        return $options;
    }

    /**
     * A slider's ends and step, rounded to numbers worth reading.
     *
     * Public and static because it is pure -- bounds and a chosen value in, a
     * control out -- and because this is the arithmetic worth checking: the
     * ends move outwards only, and an end that rounded inwards would leave the
     * cheapest flight on the far side of a handle dragged all the way over.
     *
     * The raw span comes from the results, so it is something like 1107-15941
     * or 499-3413 minutes. Snapping the ends outwards to a whole step gives
     * "$16,000" and "57h" instead of "$15,941" and "56h 53m", and stepping by
     * that same unit means every value the handle can stop on is round too.
     * The ends move outwards only, so nothing reachable is excluded.
     *
     * @param array{min: int, max: int, floor_max: int, ceiling_min: int}|null $bound
     * @param list<int> $steps allowed step sizes, smallest first
     * @return array<string, mixed>|null
     */
    public static function sliderOption(?array $bound, mixed $value, array $steps, string $kind): ?array
    {
        if ($bound === null) {
            return null;
        }

        $current = is_numeric($value) ? (int) $value : null;

        // Nothing to drag between when every option costs or lasts the same —
        // unless a ceiling is set, in which case the control has to stay: it is
        // the way back out, and a form missing the input drops the filter
        // without saying so.
        if ($bound['max'] <= $bound['min'] && $current === null) {
            return null;
        }

        // The ends have to contain the chosen ceiling as well as what is on
        // offer, or the handle gets clamped somewhere nobody asked for.
        $low = $bound['min'];
        $high = $current === null ? $bound['max'] : max($bound['max'], $current);

        ['min' => $min, 'max' => $max, 'step' => $step] = Helper::sliderScale(
            $low,
            $high,
            $steps,
            self::SLIDER_STOPS,
            ceilingOnly: true,
        );

        // A ceiling set below where the track starts is still a ceiling that
        // works; the control has to be able to show it rather than quietly
        // snapping to a stricter one.
        if ($current !== null && $current < $min) {
            $min = $current;
        }

        $value = $current === null ? $max : max($min, min($current, $max));

        return [
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'value' => $value,
            'caption' => Helper::sliderCaption($kind, $value, null, $min, $max),
            'on' => $value < $max,
        ];
    }

    /**
     * A two-handled slider's ends, step and current pair.
     *
     * Public and static for the reason sliderOption is.
     *
     * Same rounding as a single slider, but both handles matter: a layover
     * range rules out connections that are too tight as well as too long.
     *
     * @param array{min: int, max: int, floor_max: int, ceiling_min: int}|null $bound
     * @param list<int> $steps
     * @return array<string, mixed>|null
     */
    public static function rangeOption(?array $bound, mixed $value, array $steps, string $kind): ?array
    {
        if ($bound === null) {
            return null;
        }

        // Every form FlightFilters accepts, so the control shows the state the
        // filter actually applied — a slider reading "Any" over a filtered page
        // would drop the filter on the next Apply. A bare number is a ceiling
        // with no floor under it, and the floor handle rests at the bottom.
        $current = null;

        if (is_string($value) && preg_match('/^(\d{1,5})$/', $value, $match) === 1) {
            $current = [null, (int) $match[1]];
        } elseif (is_string($value) && preg_match('/^(\d{1,5})[;-](\d{1,5})$/', $value, $match) === 1) {
            $current = [(int) $match[1], (int) $match[2]];
        }

        // Nothing left to choose between and nothing chosen: no control worth
        // drawing. With a filter applied it has to stay whatever the spread,
        // since hiding it is the one way out of a narrow filter — and a form
        // missing the input drops that filter without saying so.
        if ($bound['max'] <= $bound['min'] && $current === null) {
            return null;
        }

        // The ends have to contain the chosen range as well as what is on
        // offer, or the handles get clamped to somewhere the user never asked
        // for.
        $low = $current === null || $current[0] === null
            ? $bound['min']
            : min($bound['min'], $current[0]);
        $high = $current === null ? $bound['max'] : max($bound['max'], $current[1]);

        // A floor handle down there, so the bottom end rounds down: a floor
        // under everything excludes nothing, and the track keeps showing the
        // real spread. The ceiling handle gets its own stop below.
        ['min' => $min, 'max' => $max, 'step' => $step] = Helper::sliderScale(
            $low,
            $high,
            $steps,
            self::SLIDER_STOPS,
            ceilingOnly: false,
        );

        $from = $min;
        $to = $max;

        if ($current !== null) {
            $from = $current[0] === null ? $min : max($min, min($current[0], $max));
            $to = max($from, min($current[1], $max));
        }

        // How far each handle may travel. Beyond these the results are empty
        // whatever the other handle says, and a stretch of track that can only
        // return nothing is a promise the search cannot keep. Snapped onto the
        // step grid, since that is where a handle can actually land, and
        // widened to admit a range already applied so the control can always
        // show the state it is in.
        $floorMax = min($max, $min + (int) floor(($bound['floor_max'] - $min) / $step) * $step);
        $ceilingMin = max($min, $min + (int) ceil(($bound['ceiling_min'] - $min) / $step) * $step);

        if ($current !== null) {
            $floorMax = max($floorMax, $from);
            $ceilingMin = min($ceilingMin, $to);
        }

        return [
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'from' => $from,
            'to' => $to,
            'floor_max' => max($min, $floorMax),
            'ceiling_min' => min($max, $ceilingMin),
            'caption' => Helper::sliderCaption($kind, $from, $to, $min, $max),
            'on' => $from > $min || $to < $max,
        ];
    }

    /**
     * The same search with this leg's filters dropped, leaving the other leg's
     * alone — clearing the return should not undo the outbound's.
     */
    private function clearFiltersUrl(string $prefix): string
    {
        $kept = $this->get;

        foreach (FlightFilters::queryKeys($this->prefix) as $key) {
            $kept[$key] = null;
        }

        return ($this->link)($kept);
    }

    /**
     * A note about the leg that is not on screen, or null when it is unfiltered.
     *
     * Each leg of a round trip keeps its own filters, so standing on one of
     * them the other's narrowing is invisible — the result count moves for
     * reasons the sidebar does not explain.
     *
     * @return array{leg: string, count: int}|null
     */
    private function otherLegNote(string $prefix): ?array
    {
        $step = $this->data->step;

        // Only a round trip mid-choice has another leg to speak of: a one-way
        // search has none, and step 3 lists nothing to filter.
        if ($step === null || $step === 3) {
            return null;
        }

        $otherPrefix = $this->prefix === '' ? FlightFilters::RETURN_PREFIX : '';
        $count = FlightFilters::fromQuery($this->get, $otherPrefix)->appliedCount();

        if ($count === 0) {
            return null;
        }

        return [
            'leg' => $otherPrefix === '' ? 'departing' : 'returning',
            'count' => $count,
        ];
    }

    /**
     * This leg's filter values, keyed without the prefix so the option builders
     * can work in plain names.
     *
     * @return array<string, string|list<string>|null>
     */
    private function legFilterQuery(string $prefix): array
    {
        $own = [];

        foreach (FlightFilters::QUERY_KEYS as $key) {
            $own[$key] = $this->get[$this->prefix . $key] ?? null;
        }

        return $own;
    }
}
