<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use stdClass;
use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\AircraftRepository;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\Service\FlightFinder;
use TripBuilder\TripType;
use TripBuilder\View\ItineraryPresenter;
use TripBuilder\View\TwigRenderer;

class SearchController extends AbstractController
{
    private const string GET_HASH = 'hash',
        GET_FROM = 'from',
        GET_TO = 'to',
        GET_DEPART = 'depart',
        GET_RETURN = 'return',
        GET_TRIPTYPE = 'triptype',
        GET_CLASS = 'class',
        GET_PAGE = 'page',
        // Leg ids of the halves already chosen in a round trip. The outbound
        // moves the search from step 1 (departing) to step 2 (returning); adding
        // the return moves it to step 3, the assembled package.
        GET_DEPART_ITIN = 'depart_itin',
        GET_RETURN_ITIN = 'return_itin',
        GET_SORT = 'sort';

    private const string DEFAULT_SORT = 'price';

    // Roughly how many positions a slider handle should have.
    private const int SLIDER_STOPS = 40;

    // Step sizes a slider may round to, smallest first. Money climbs in the
    // usual 1/2.5/5 pattern; time sticks to fractions of an hour, so a handle
    // never stops somewhere like 41h 51m.
    private const array PRICE_STEPS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];
    private const array DURATION_STEPS = [5, 10, 15, 30, 60, 120, 180, 360, 720];

    private array $get;

    private ?stdClass $data = null;

    private ?ItineraryPresenter $presenter = null;

    public function index(): void
    {
        try {
            // Handle GET data
            $this->setGet([
                self::GET_HASH => $_GET[Config::get('search.form.input.hash')] ?? null,
                self::GET_FROM => strtoupper($_GET[Config::get('search.form.input.depart_place')] ?? ''),
                self::GET_TO => strtoupper($_GET[Config::get('search.form.input.arrive_place')] ?? ''),
                self::GET_DEPART => $_GET[Config::get('search.form.input.depart_date')] ?? null,
                self::GET_RETURN => $_GET[Config::get('search.form.input.return_date')] ?? null,
                self::GET_TRIPTYPE => $_GET[Config::get('search.form.input.triptype')] ?? null,
                self::GET_CLASS => $_GET[Config::get('search.form.input.class')] ?? null,
                self::GET_PAGE => filter_var(
                    $_GET[Config::get('search.form.input.page')] ?? 1,
                    FILTER_VALIDATE_INT,
                    ['options' => ['default' => 1, 'min_range' => 1]],
                ),
                self::GET_DEPART_ITIN => $_GET[self::GET_DEPART_ITIN] ?? null,
                self::GET_RETURN_ITIN => $_GET[self::GET_RETURN_ITIN] ?? null,
                // Sort and filters ride in the query string, so stepUrl() and
                // pageUrl() — which rebuild from $this->get — carry them across
                // pagination, the step transitions and a shared link for free.
                self::GET_SORT => $_GET[self::GET_SORT] ?? null,
                ...$this->filterQuery(),
            ]);

            // Convert search hash to url and redirect
            $this->checkHash();

            // If one of important params is empty or not provided – redirect to index page
            if (empty($this->get[self::GET_TRIPTYPE])
                || empty($this->get[self::GET_FROM])
                || empty($this->get[self::GET_TO])
                || empty($this->get[self::GET_DEPART])
            ) {
                echo '<script>window.location.replace("/");</script>';

                return;
            }

            $query = new FlightSearchQuery(
                currentPage: $this->get[self::GET_PAGE] ?? 1,
                sort: $this->sort(),
                from: $this->get[self::GET_FROM],
                to: $this->get[self::GET_TO],
                departDate: $this->get[self::GET_DEPART],
                returnDate: $this->get[self::GET_RETURN] ?? '',
                adultNum: 1, // FIXME: now we provide only 1 adult count
                childNum: 0, // FIXME: now we provide only 0 child count
                filters: FlightFilters::fromQuery($this->get),
            );

            // Call the flight search directly; reuse the nested-object shape the
            // removed HTTP round-trip used to produce (array -> JSON -> stdClass).
            $payload = new FlightFinder($this->connection())->search(
                $query,
                TripType::from($this->get[self::GET_TRIPTYPE]),
                $this->parseIds((string) ($this->get[self::GET_DEPART_ITIN] ?? '')),
                $this->parseIds((string) ($this->get[self::GET_RETURN_ITIN] ?? '')),
            );

            $decoded = json_decode((string) json_encode($payload), false);

            if (! $decoded instanceof stdClass) {
                throw new Exception('Failed to build the flights response');
            }

            $this->setData($decoded);

            // Recording search stat
            $this->searchStat();

            $total_flights = $this->data->total_flights;

            echo new TwigRenderer()->renderPage('search/view.html.twig', [
                // Lead form + sidebar + cards share the resolved query context.
                'triptype' => $this->get[self::GET_TRIPTYPE],
                'depart_code' => $this->get[self::GET_FROM],
                'arrive_code' => $this->get[self::GET_TO],
                'depart_city' => $this->data->depart,
                'arrive_city' => $this->data->arrive,
                'depart_date' => $this->get[self::GET_DEPART],
                'return_date' => $this->get[self::GET_RETURN],
                // Sidebar
                'form_url' => sprintf(
                    '%s?%s',
                    Helper::getUrlPath(),
                    $this->queryString(array_merge($this->get, [self::GET_PAGE => null])),
                ),
                // Filter forms submit with GET, so they post to the bare path
                // and carry the rest of the search as hidden fields.
                'form_path' => Helper::getUrlPath(),
                'session_sort' => $this->sort(),
                'sidebar' => $this->sidebarFilters(),
                // What the sidebar needs to draw itself: the filters currently
                // applied, and which options are worth offering at all.
                'filters' => $this->filterQuery(),
                'available' => (array) $this->data->available,
                // Hidden fields a GET form needs so submitting one control does
                // not drop the rest of the search.
                'carried_query' => $this->carried([self::GET_SORT]),
                // The filter form supplies filter values from its own controls,
                // so it must not also carry the applied ones — an unchecked box
                // would otherwise be re-submitted as a hidden field.
                'carried_search' => $this->carried([self::GET_SORT, ...FlightFilters::QUERY_KEYS]),
                // Which half of a round trip is being chosen (null for one way),
                // and the outbound already picked, if any.
                'step' => $this->data->step,
                'step_title' => $this->stepTitle(),
                'step_route' => $this->stepRoute(),
                'step_date' => $this->data->step === 2
                    ? $this->get[self::GET_RETURN]
                    : $this->get[self::GET_DEPART],
                'price_mode' => $this->data->price_mode,
                'selected' => $this->data->selected === null
                    ? null
                    : $this->presenter()->direction($this->data->selected)['direction'],
                'selected_price' => $this->data->selected_price === null
                    ? null
                    : number_format((float) $this->data->selected_price, 2),
                'selected_return' => $this->data->selected_return === null
                    ? null
                    : $this->presenter()->direction($this->data->selected_return)['direction'],
                'selected_return_price' => $this->data->selected_return_price === null
                    ? null
                    : number_format((float) $this->data->selected_return_price, 2),
                'package_price' => $this->data->package_price === null
                    ? null
                    : $this->presenter()->priceParts((float) $this->data->package_price),
                'package_ids' => [
                    'outbound' => implode(',', array_map(intval(...), (array) $this->data->selected_ids)),
                    'return' => implode(',', array_map(intval(...), (array) $this->data->selected_return_ids)),
                ],
                'depart_date_label' => $this->get[self::GET_DEPART],
                'return_date_label' => $this->get[self::GET_RETURN],
                // Changing one half keeps the other, so the traveller returns
                // straight to the package once they have re-picked.
                'change_url' => $this->stepUrl(null, keepReturn: true),
                'change_return_url' => $this->stepUrl(
                    array_map(intval(...), (array) $this->data->selected_ids),
                    keepReturn: false,
                ),
                // Flights / no-result
                'total_flights' => $total_flights,
                'total_flights_text' => Helper::plural((int) $total_flights, 'option', showNumber: true),
                'flights' => $total_flights != 0 ? $this->buildFlights() : [],
                'pagination' => $total_flights != 0 ? $this->buildPagination() : null,
                'not_found_img' => Cdn::getUrl(sprintf(
                    '%s/%s',
                    Config::get('site.static.endpoint.images'),
                    'no-results.png',
                )),
            ]);
        } catch (Exception $e) {
            error_log('Search page failed: ' . $e->getMessage());
            echo 'Something went wrong while searching for flights. Please try again later.';
        }
    }

    /**
     * @throws Exception|\Twig\Error\Error
     */
    private function checkHash(): void
    {
        if ($this->get['hash']) {
            $search = new SearchRepository($this->connection())->findByHash($this->get['hash']);

            $search_params = http_build_query([
                self::GET_FROM => $search[self::GET_FROM . '_code'],
                self::GET_TO => $search[self::GET_TO . '_code'],
                self::GET_DEPART => $search[self::GET_DEPART],
                self::GET_RETURN => $search[self::GET_RETURN],
                self::GET_TRIPTYPE => $search[self::GET_TRIPTYPE],
                self::GET_CLASS => 'economy', // FIXME: we need real class here
            ]);

            echo new TwigRenderer()->render('search/redirect.html.twig', [
                'image_url' => Cdn::getUrl(sprintf(
                    '%s/search_redirect.gif',
                    Config::get('site.static.endpoint.images'),
                )),
                'search_params' => $search_params,
            ]);

            die();
        }
    }

    private function searchStat(): void
    {
        // Prevent too many counts from one user: only the first page of a
        // search counts, so paging and re-filtering do not inflate it.
        if ($this->get[self::GET_PAGE] != 1) {
            return;
        }

        // Calculating search hash
        $hash = md5(sprintf(
            '%s:%s:%s:%s:%s',
            $this->get[self::GET_FROM],
            $this->get[self::GET_TO],
            $this->get[self::GET_DEPART],
            $this->get[self::GET_RETURN],
            $this->get[self::GET_TRIPTYPE],
        ));

        // Insert or update search
        new SearchRepository($this->connection())->record(
            $hash,
            $this->get[self::GET_FROM],
            trim(preg_replace('/\([^)]+\)/', '', $this->data->depart)),
            $this->get[self::GET_TO],
            trim(preg_replace('/\([^)]+\)/', '', $this->data->arrive)),
            $this->get[self::GET_DEPART],
            $this->get[self::GET_RETURN],
            $this->get[self::GET_TRIPTYPE],
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
    private function sidebarFilters(): array
    {
        $available = (array) $this->data->available;
        // The response reaches here through json_decode's object mode, so a map
        // of maps arrives as nested stdClass. Availability is a map of lists and
        // survives the cast; bounds needs the round trip.
        $bounds = (array) json_decode((string) json_encode($this->data->bounds), true);
        $chosen = $this->filterQuery();

        $codes = static fn(string $dimension): array => array_map(
            strval(...),
            (array) ($available[$dimension] ?? []),
        );

        return [
            'stops' => $this->stopOptions($codes(FlightFilters::DIM_STOPS), $chosen),
            'airlines' => $this->airlineOptions($codes(FlightFilters::DIM_AIRLINES), $chosen),
            'layover_airports' => $this->airportOptions(
                $codes(FlightFilters::DIM_LAYOVER_AIRPORTS),
                FlightFilters::DIM_LAYOVER_AIRPORTS,
                $chosen,
            ),
            'depart_airports' => $this->airportOptions(
                $codes(FlightFilters::DIM_DEPART_AIRPORTS),
                FlightFilters::DIM_DEPART_AIRPORTS,
                $chosen,
            ),
            'arrive_airports' => $this->airportOptions(
                $codes(FlightFilters::DIM_ARRIVE_AIRPORTS),
                FlightFilters::DIM_ARRIVE_AIRPORTS,
                $chosen,
            ),
            'aircraft' => $this->aircraftOptions($codes(FlightFilters::DIM_AIRCRAFT), $chosen),
            'arrive_dates' => $this->dateOptions($codes(FlightFilters::DIM_ARRIVE_DATE), $chosen),
            'depart_buckets' => $this->bucketOptions(
                $codes(FlightFilters::DIM_DEPART_TIME),
                FlightFilters::QUERY_DEPART_BUCKETS,
                $chosen,
            ),
            'arrive_buckets' => $this->bucketOptions(
                $codes(FlightFilters::DIM_ARRIVE_TIME),
                FlightFilters::QUERY_ARRIVE_BUCKETS,
                $chosen,
            ),
            // A toggle is available when switching it on would leave something.
            'toggles' => [
                FlightFilters::DIM_SINGLE_CARRIER => [
                    'label' => 'All flights with one airline',
                    'hint' => 'One carrier for the whole trip, so bags are checked through.',
                    'on' => isset($chosen[FlightFilters::DIM_SINGLE_CARRIER]),
                    'available' => (bool) ($available[FlightFilters::DIM_SINGLE_CARRIER] ?? false),
                ],
                FlightFilters::DIM_NO_VISA => [
                    'label' => 'No transit visa needed',
                    'hint' => 'Hides connections in a country that is neither your origin nor your'
                        . ' destination. Check the requirements yourself before booking.',
                    'on' => isset($chosen[FlightFilters::DIM_NO_VISA]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_VISA] ?? false),
                ],
                FlightFilters::DIM_NO_GULF => [
                    'label' => 'No layovers in the Gulf',
                    'hint' => 'Hides connections in the United Arab Emirates, Saudi Arabia, Qatar,'
                        . ' Kuwait, Bahrain and Oman.',
                    'on' => isset($chosen[FlightFilters::DIM_NO_GULF]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_GULF] ?? false),
                ],
                FlightFilters::DIM_NO_NIGHT => [
                    'label' => 'No overnight layovers',
                    'hint' => 'Hides connections spent waiting between 23:00 and 06:00.',
                    'on' => isset($chosen[FlightFilters::DIM_NO_NIGHT]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_NIGHT] ?? false),
                ],
            ],
            'sliders' => [
                FlightFilters::DIM_PRICE => $this->sliderOption(
                    $bounds[FlightFilters::DIM_PRICE] ?? null,
                    $chosen[FlightFilters::DIM_PRICE] ?? null,
                    self::PRICE_STEPS,
                ),
                FlightFilters::DIM_DURATION => $this->sliderOption(
                    $bounds[FlightFilters::DIM_DURATION] ?? null,
                    $chosen[FlightFilters::DIM_DURATION] ?? null,
                    self::DURATION_STEPS,
                ),
            ],
            // Which groups hold something the visitor has set, so a filter is
            // never left hidden behind a collapsed heading.
            'active' => $this->activeSections($chosen),
            // Whether anything is filtered, so the sidebar can offer a way out.
            'any_applied' => !FlightFilters::fromQuery($this->get)->isEmpty(),
            'clear_url' => $this->clearFiltersUrl(),
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
            'stops' => [FlightFilters::DIM_STOPS],
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
                if (($chosen[$key] ?? null) !== null) {
                    $active[$section] = true;
                    break;
                }
            }
        }

        return $active;
    }

    /**
     * Values chosen for one filter key, from the query as it arrived.
     *
     * @param array<string, string|list<string>|null> $chosen
     * @return list<string>
     */
    private function selected(array $chosen, string $key): array
    {
        return FlightFilters::values($chosen[$key] ?? null);
    }

    /**
     * @param list<string> $available
     * @param array<string, string|list<string>|null> $chosen
     * @return list<array<string, mixed>>
     */
    private function stopOptions(array $available, array $chosen): array
    {
        $picked = $this->selected($chosen, FlightFilters::DIM_STOPS);
        $options = [];

        // Every level the search can produce, so an unreachable one greys out
        // in place instead of disappearing from the list.
        for ($stops = 0; $stops <= (int) Config::get('search.connections.max_stops', 2); $stops++) {
            $options[] = [
                'value' => (string) $stops,
                'label' => $this->presenter()->stopsLabel($stops),
                'sub' => null,
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

        $picked = $this->selected($chosen, FlightFilters::DIM_AIRLINES);

        // The lookup returns rows alphabetically; $available is ordered by how
        // many itineraries each carrier flies, which is the order worth showing.
        $titles = [];

        foreach (new AirlineRepository($this->connection())->search($available, false) as $airline) {
            $titles[(string) $airline['code']] = (string) $airline['title'];
        }

        $options = [];

        foreach ($available as $code) {
            $options[] = [
                'value' => $code,
                'label' => $titles[$code] ?? $code,
                'sub' => $code,
                'logo_url' => $this->presenter()->carrierLogo($code),
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

        $picked = $this->selected($chosen, $key);
        $rows = [];

        foreach (new AirportRepository($this->connection())->byCodes($available) as $airport) {
            $rows[(string) $airport['code']] = $airport;
        }

        $options = [];

        // Busiest first, as the availability list came back.
        foreach ($available as $code) {
            $airport = $rows[$code] ?? null;

            $options[] = [
                'value' => $code,
                'label' => $airport === null ? $code : (string) $airport['city'],
                'sub' => $airport === null ? null : trim(sprintf('%s %s', $airport['title'], $code)),
                'note' => $airport === null ? '' : (string) ($airport['country'] ?? ''),
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

        $picked = $this->selected($chosen, FlightFilters::DIM_AIRCRAFT);
        $types = new AircraftRepository($this->connection())->all();
        $options = [];

        foreach ($available as $code) {
            $options[] = [
                'value' => $code,
                'label' => $types[$code]['title'] ?? $code,
                'sub' => null,
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
        $picked = $this->selected($chosen, FlightFilters::DIM_ARRIVE_DATE);
        $options = [];

        sort($available);

        foreach ($available as $date) {
            $options[] = [
                'value' => $date,
                'label' => date('j F, D', (int) strtotime($date)),
                'sub' => null,
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
        $picked = array_map(strtolower(...), FlightFilters::values($chosen[$key] ?? null));
        $options = [];

        /** @var array<string, array{title: string, icon: string, from: int, to: int}> $buckets */
        $buckets = (array) Config::get('search.filters.time_buckets', []);

        foreach ($buckets as $bucket => $meta) {
            $options[] = [
                'value' => (string) $bucket,
                'label' => (string) $meta['title'],
                'icon' => (string) $meta['icon'],
                'checked' => in_array((string) $bucket, $picked, true),
                'available' => in_array((string) $bucket, $available, true),
            ];
        }

        return $options;
    }

    /**
     * A slider's ends and step, rounded to numbers worth reading.
     *
     * The raw span comes from the results, so it is something like 1107-15941
     * or 499-3413 minutes. Snapping the ends outwards to a whole step gives
     * "$16,000" and "57h" instead of "$15,941" and "56h 53m", and stepping by
     * that same unit means every value the handle can stop on is round too.
     * The ends move outwards only, so nothing reachable is excluded.
     *
     * @param array{min: int, max: int}|null $bound
     * @param list<int> $steps allowed step sizes, smallest first
     * @return array<string, mixed>|null
     */
    private function sliderOption(?array $bound, mixed $value, array $steps): ?array
    {
        // Nothing to drag between when every option costs or lasts the same.
        if ($bound === null || $bound['max'] <= $bound['min']) {
            return null;
        }

        $step = $this->niceStep($bound['max'] - $bound['min'], $steps);
        $min = (int) floor($bound['min'] / $step) * $step;
        $max = (int) ceil($bound['max'] / $step) * $step;

        return [
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'value' => is_numeric($value) ? max($min, min((int) $value, $max)) : $max,
        ];
    }

    /**
     * The smallest allowed step that keeps the handle to roughly SLIDER_STOPS
     * positions — fine enough to be useful, coarse enough to stay readable.
     *
     * @param list<int> $steps
     */
    private function niceStep(int $span, array $steps): int
    {
        $wanted = max(1, (int) round($span / self::SLIDER_STOPS));

        foreach ($steps as $step) {
            if ($wanted <= $step) {
                return $step;
            }
        }

        return $steps[count($steps) - 1];
    }

    /**
     * The same search with every filter dropped.
     */
    private function clearFiltersUrl(): string
    {
        $kept = $this->get;

        foreach (FlightFilters::QUERY_KEYS as $key) {
            $kept[$key] = null;
        }

        return sprintf(
            '%s?%s',
            Helper::getUrlPath(),
            $this->queryString(array_merge($kept, [self::GET_PAGE => null])),
        );
    }

    /**
     * Normalise the flights response into the per-card view-model the
     * templates render (prices, carrier logos, per-leg flight info).
     *
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    private function buildFlights(): array
    {
        $flights = [];

        $step = $this->data->step;
        $cheapest = $this->data->cheapest_total;
        // Naming one option "cheapest" only says something when there is more
        // than one to be cheaper than.
        $compare = $cheapest !== null && $this->data->total_flights > 1;

        foreach ($this->data->flights as $flight) {
            $built = $this->presenter()->direction($flight->itinerary);
            $total = (float) $flight->price_base + (float) $flight->price_tax;
            $difference = $compare ? $total - (float) $cheapest : null;

            $flights[] = [
                // Each round-trip choice adds a half to the package (a link);
                // only a one-way search books straight from the list.
                'select_url' => match ($step) {
                    1 => $this->stepUrl($built['ids'], keepReturn: true),
                    2 => $this->returnStepUrl($built['ids']),
                    default => null,
                },
                'outbound_ids' => $built['ids'],
                'return_ids' => [],
                'price' => $this->presenter()->priceParts($total),
                // Whole pounds/dollars: the cents of a difference are noise.
                'is_cheapest' => $difference !== null && $difference < 0.5,
                'price_difference' => $difference !== null && $difference >= 0.5
                    ? number_format($difference)
                    : null,
                'price_base' => number_format((float) $flight->price_base, 2),
                'price_tax' => number_format((float) $flight->price_tax, 2),
                'price_gst' => number_format(0, 2),
                'price_qst' => number_format(0, 2),
                // Path only; the browser resolves it against its own origin.
                'share_url' => match ($step) {
                    1 => $this->stepUrl($built['ids'], keepReturn: true),
                    2 => $this->returnStepUrl($built['ids']),
                    default => $this->stepUrl(null),
                },
                'itinerary' => $built['direction'],
            ];
        }

        return $flights;
    }

    /**
     * Heading for the current step of the search.
     */
    private function stepTitle(): string
    {
        return match ($this->data->step) {
            1 => 'Choose your departing flight',
            2 => 'Choose your returning flight',
            3 => 'Your round trip',
            default => 'Choose your flight',
        };
    }

    /**
     * "City (CODE) → City (CODE)" for the current step, reversed on the return.
     */
    private function stepRoute(): string
    {
        return match ($this->data->step) {
            2 => sprintf('%s → %s', $this->data->arrive, $this->data->depart),
            3 => sprintf('%s ⇄ %s', $this->data->depart, $this->data->arrive),
            default => sprintf('%s → %s', $this->data->depart, $this->data->arrive),
        };
    }

    /**
     * URL for this search with the outbound choice set (or cleared when null),
     * always returning to page one. `keepReturn` decides whether an already
     * chosen return survives — re-picking a departure keeps it, so the traveller
     * lands back on the package, while changing the return clears it.
     *
     * @param list<int>|null $ids
     */
    private function stepUrl(?array $ids, bool $keepReturn = false): string
    {
        return sprintf(
            '%s?%s',
            Helper::getUrlPath(),
            $this->queryString(array_merge($this->get, [
                self::GET_DEPART_ITIN => $ids === null ? null : implode(',', $ids),
                self::GET_RETURN_ITIN => $keepReturn ? ($this->get[self::GET_RETURN_ITIN] ?? null) : null,
                self::GET_PAGE => null,
            ])),
        );
    }

    /**
     * URL that adds the chosen return to the package, keeping the outbound.
     *
     * @param list<int> $ids
     */
    private function returnStepUrl(array $ids): string
    {
        return sprintf(
            '%s?%s',
            Helper::getUrlPath(),
            $this->queryString(array_merge($this->get, [
                self::GET_RETURN_ITIN => implode(',', $ids),
                self::GET_PAGE => null,
            ])),
        );
    }

    /**
     * Parse a comma-separated list of positive integer leg ids.
     *
     * @return list<int>
     */
    private function parseIds(string $csv): array
    {
        $ids = [];

        foreach (explode(',', $csv) as $part) {
            $id = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return $ids;
    }


    /**
     * Build the pagination view-model: previous/next URLs and the page items
     * (link, current, or skipped ellipsis). Returns null for a single page.
     *
     * @return array<string, mixed>|null
     */
    private function buildPagination(): ?array
    {
        $totalPages = $this->data->total_pages;

        // If we have only 1 page - skip render
        if ($totalPages == 1) {
            return null;
        }

        $page = $this->get[self::GET_PAGE];

        $items = [];
        $skipPages = false;

        for ($i = 1; $i <= $totalPages; $i++) {
            $isFirstPage = $i === 1;
            $isLastPage = $i === $totalPages;
            $isWithinRange = abs($i - $page) <= 1;

            if ($totalPages >= 10 && !$isFirstPage && !$isLastPage && !$isWithinRange) {
                $skipPages = true;
                continue;
            }

            if ($skipPages) {
                $items[] = ['type' => 'skipped'];
                $skipPages = false;
            }

            $items[] = $i != $page
                ? ['type' => 'link', 'url' => $this->pageUrl($i), 'number' => $i]
                : ['type' => 'current', 'number' => $i];
        }

        return [
            'prev' => $page > 1 ? $this->pageUrl($page - 1) : null,
            'next' => $page < $totalPages ? $this->pageUrl($page + 1) : null,
            'items' => $items,
        ];
    }

    private function pageUrl(int $page): string
    {
        return sprintf(
            '%s?%s',
            Helper::getUrlPath(),
            $this->queryString(array_merge($this->get, [self::GET_PAGE => $page])),
        );
    }

    /**
     * A query string with commas left as commas.
     *
     * http_build_query percent-encodes them, which turns a readable
     * `airlines=BA,AI` into `airlines=BA%2CAI`. A comma is a legal sub-delimiter
     * in a query string, so decoding them back costs nothing and the list
     * filters stay legible in the address bar.
     *
     * @param array<string, mixed> $params
     */
    private function queryString(array $params): string
    {
        return str_replace('%2C', ',', http_build_query($params));
    }

    /**
     * The current query as hidden-field material, minus the keys a form sets
     * itself. Page and hash always go: a new filtering starts at page one, and
     * the hash has already been resolved to a real URL.
     *
     * @param list<string> $without
     * @return array<string, string|list<string>>
     */
    private function carried(array $without): array
    {
        $drop = [self::GET_PAGE, self::GET_HASH, ...$without];

        return array_filter(
            $this->get,
            static fn(mixed $v, string $k): bool => $v !== null && $v !== '' && $v !== []
                && !in_array($k, $drop, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The filter query keys as they arrived, untouched.
     *
     * They are kept verbatim rather than re-serialised from the parsed filters
     * so that every URL the page builds reproduces exactly the search that
     * produced it — including a value the parser rejected, which stays visible
     * in the address bar instead of silently vanishing.
     *
     * @return array<string, string|list<string>|null>
     */
    private function filterQuery(): array
    {
        $carried = [];

        foreach (FlightFilters::QUERY_KEYS as $key) {
            $value = $_GET[$key] ?? null;

            // A checkbox group arrives as an array, a shared link as a string.
            $carried[$key] = (is_string($value) && $value !== '') || (is_array($value) && $value !== [])
                ? $value
                : null;
        }

        return $carried;
    }

    /**
     * The chosen sort, defaulting to price.
     */
    private function sort(): string
    {
        $sort = $this->get[self::GET_SORT] ?? null;

        return is_string($sort) && $sort !== '' ? $sort : self::DEFAULT_SORT;
    }

    private function presenter(): ItineraryPresenter
    {
        return $this->presenter ??= new ItineraryPresenter();
    }

    private function setGet(array $get): void
    {
        // Normalise the trip type, defaulting to one-way for invalid input.
        $get[self::GET_TRIPTYPE] = TripType::fromRequest($get[self::GET_TRIPTYPE])->value;

        $this->get = $get;
    }

    private function setData(stdClass $data): void
    {
        $this->data = $data;
    }

}
