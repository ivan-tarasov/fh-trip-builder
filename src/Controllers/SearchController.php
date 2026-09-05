<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use stdClass;
use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Http\Input;
use TripBuilder\Repository\AircraftRepository;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\FareBrandRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\SearchUrl;
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
        GET_SHOWN = 'shown',
        // Leg ids of the halves already chosen in a round trip. The outbound
        // moves the search from step 1 (departing) to step 2 (returning); adding
        // the return moves it to step 3, the assembled package.
        GET_DEPART_ITIN = 'depart_itin',
        GET_RETURN_ITIN = 'return_itin',
        // The half being re-picked, carried by a "Change" link so the list can
        // point out the flight that is being replaced. Otherwise the traveller
        // arrives at a page of near-identical rows with no idea which one they
        // already have.
        GET_CURRENT = 'current',
        GET_SORT = 'sort';

    // The balanced sort, as the market leads with. It is the one sort ranked
    // across the whole result set rather than by ORDER BY, so it costs a pass
    // over the candidates that the others do not.
    /**
     * The keys SearchUrl spells into the path. Everything else the search reads
     * -- sort, paging, the chosen legs, and every filter -- stays in the query
     * string, because it describes the screen rather than the trip.
     */
    private const array PATH_KEYS = [
        self::GET_FROM,
        self::GET_TO,
        self::GET_DEPART,
        self::GET_RETURN,
        self::GET_TRIPTYPE,
        self::GET_CLASS,
    ];

    private const string DEFAULT_SORT = 'recommended';

    // Ten is a first screen; after that the visitor is scanning, and more per
    // load means fewer round trips for the same scroll. MAX_SHOWN bounds what a
    // crafted URL can ask us to hydrate at once — ten loads' worth.
    private const int FIRST_SLICE = 10;
    private const int NEXT_SLICE = 20;
    private const int MAX_SHOWN = 210;

    // The three that earn a tab of their own; the rest sit in the dropdown.
    private const array PRIMARY_SORTS = ['recommended', 'price', 'duration'];

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

    /** @var array<string, array<array-key, float>> */
    private array $optionPrices = [];

    private ?SearchUrl $searchUrl = null;

    public function index(): void
    {
        try {
            $query = $this->request->query;

            // Handle GET data
            $this->setGet([
                self::GET_HASH => $query->nullableStr((string) Config::get('search.form.input.hash')),
                self::GET_FROM => strtoupper($query->str((string) Config::get('search.form.input.depart_place'))),
                self::GET_TO => strtoupper($query->str((string) Config::get('search.form.input.arrive_place'))),
                self::GET_DEPART => $query->nullableStr((string) Config::get('search.form.input.depart_date')),
                self::GET_RETURN => $query->nullableStr((string) Config::get('search.form.input.return_date')),
                self::GET_TRIPTYPE => $query->nullableStr((string) Config::get('search.form.input.triptype')),
                self::GET_CLASS => $query->nullableStr((string) Config::get('search.form.input.class')),
                // How many results to render, not which page: the list grows
                // by appending, so the URL describes the screen and a refresh
                // or a Back lands on exactly what was there.
                self::GET_SHOWN => $query->intWithin(
                    (string) Config::get('search.form.input.shown'),
                    self::FIRST_SLICE,
                    self::FIRST_SLICE,
                    self::MAX_SHOWN,
                ),
                self::GET_DEPART_ITIN => $query->nullableStr(self::GET_DEPART_ITIN),
                self::GET_RETURN_ITIN => $query->nullableStr(self::GET_RETURN_ITIN),
                // Sort and filters ride in the query string, so stepUrl() and
                // moreUrl() — which rebuild from $this->get — carry them across
                // a longer list, the step transitions and a shared link for
                // free.
                self::GET_CURRENT => $query->nullableStr(self::GET_CURRENT),
                self::GET_SORT => $query->nullableStr(self::GET_SORT),
                ...$this->filterQuery(),
            ]);

            // Convert search hash to url and redirect
            $this->checkHash();

            // The search itself comes from the path when there is one, and from
            // the query string when the link predates it.
            $this->searchUrl = SearchUrl::parse($this->request->path())
                ?? SearchUrl::fromQuery($query);

            if ($this->searchUrl === null) {
                echo '<script>window.location.replace("/");</script>';

                return;
            }

            $this->setGet([...$this->get, ...$this->identity()]);

            // One redirect covers both an older query-string link and a path
            // spelled a way this page would not write -- `W10` for one adult,
            // say. Parse whatever arrived, write it back out, and move only if
            // the spellings differ. path() is a pure function of a parsed
            // search and parse(path($x)) is $x, so this settles in one hop and
            // cannot ping-pong.
            //
            // Never on a fragment request: the JS injects the answer as cards,
            // and fetch follows redirects, so it would splice a whole page --
            // header, footer and all -- into the results list.
            if ($this->request->path() !== $this->searchUrl->path() && !$this->request->isFragment()) {
                $this->bounce($this->link($this->get), 301);

                return;
            }

            $shown = (int) $this->get[self::GET_SHOWN];
            // A fragment request already holds everything above `from`, so it
            // asks only for the part it is missing.
            $from = $this->fragmentFrom($shown);

            $query = new FlightSearchQuery(
                offset: $from,
                limit: $shown - $from,
                sort: $this->sort(),
                from: $this->get[self::GET_FROM],
                to: $this->get[self::GET_TO],
                departDate: $this->get[self::GET_DEPART],
                returnDate: $this->get[self::GET_RETURN] ?? '',
                party: $this->searchUrl->party(),
                cabin: CabinClass::fromRequest($this->get[self::GET_CLASS] ?? null),
                filters: FlightFilters::fromQuery($this->get, party: $this->searchUrl->party()),
                returnFilters: FlightFilters::fromQuery(
                    $this->get,
                    FlightFilters::RETURN_PREFIX,
                    $this->searchUrl->party(),
                ),
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

            // "Load more" asks for cards, not a page: same query, same filters,
            // same sort — only the window differs. Rendering the one partial
            // the full page uses keeps the two from drifting apart.
            $cabin = CabinClass::fromRequest($this->get[self::GET_CLASS] ?? null);

            if ($this->isFragment()) {
                echo new TwigRenderer()->render('search/cards/list.html.twig', [
                    'flights' => $total_flights != 0 ? $this->buildFlights() : [],
                    'step' => $this->data->step,
                    'price_mode' => $this->data->price_mode,
                    'show_more' => $total_flights != 0 ? $this->buildShowMore() : null,
                    // The cards link to checkout, and that link carries the
                    // cabin — so an appended card needs it as much as a
                    // first-paint one.
                    'cabin' => $cabin,
                ]);

                return;
            }

            echo new TwigRenderer()->renderPage('search/view.html.twig', [
                // So the form above the results shows the party that was
                // searched for rather than resetting to one adult.
                'party' => $this->searchUrl->party(),
                'party_label' => $this->searchUrl->party()->label(),
                // Carried onto the checkout links so the party survives the hop
                // -- the legs say what is being bought, not for how many.
                'checkout_pax' => $this->checkoutPax(),
                // Lead form + sidebar + cards share the resolved query context.
                'triptype' => $this->get[self::GET_TRIPTYPE],
                'depart_code' => $this->get[self::GET_FROM],
                'arrive_code' => $this->get[self::GET_TO],
                // So the results page's own form comes back showing the cabin
                // that produced these results.
                'cabin' => $cabin,
                'depart_city' => $this->data->depart,
                'arrive_city' => $this->data->arrive,
                'depart_date' => $this->get[self::GET_DEPART],
                'return_date' => $this->get[self::GET_RETURN],
                // Filter forms submit with GET, so they post to the search's
                // own path and carry only the rest -- sort and filters -- as
                // hidden fields. The search itself is in that path now.
                'form_path' => $this->searchPath(),
                'session_sort' => $this->sort(),
                'default_sort' => self::DEFAULT_SORT,
                // Sorting moved out of the sidebar and above the results, where
                // each option can show what choosing it would get you.
                'sort_tabs' => $this->sortTabs(),
                'sidebar' => $this->sidebarFilters(),
                // What the sidebar needs to draw itself: the filters currently
                // applied, and which options are worth offering at all.
                'filters' => $this->filterQuery(),
                'available' => (array) $this->data->available,
                // Hidden fields a GET form needs so submitting one control does
                // not drop the rest of the search.
                // The filter form supplies filter values from its own controls,
                // so it must not also carry the applied ones — an unchecked box
                // would otherwise be re-submitted as a hidden field.
                'carried_search' => $this->carried([
                    self::GET_SORT,
                    ...FlightFilters::queryKeys($this->filterPrefix()),
                ]),
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
                // What the ticket allows, folded to the strictest leg of each
                // direction. Baggage is the commonest reason a trip gets
                // abandoned at payment, so it belongs on the page where the
                // flights can still be swapped rather than only on the one
                // where a form has to be filled in first.
                'included' => $this->data->step === 3 ? $this->includedRules() : null,
                'package_ids' => [
                    'outbound' => implode(',', array_map(intval(...), (array) $this->data->selected_ids)),
                    'return' => implode(',', array_map(intval(...), (array) $this->data->selected_return_ids)),
                ],
                'depart_date_label' => $this->get[self::GET_DEPART],
                'return_date_label' => $this->get[self::GET_RETURN],
                // Changing one half keeps the other, so the traveller returns
                // straight to the package once they have re-picked.
                'change_url' => $this->stepUrl(
                    null,
                    keepReturn: true,
                    current: array_map(intval(...), (array) $this->data->selected_ids),
                ),
                'change_return_url' => $this->stepUrl(
                    array_map(intval(...), (array) $this->data->selected_ids),
                    keepReturn: false,
                    current: array_map(intval(...), (array) $this->data->selected_return_ids),
                ),
                // Flights / no-result
                'total_flights' => $total_flights,
                'total_flights_text' => Helper::plural((int) $total_flights, 'option', showNumber: true),
                'flights' => $total_flights != 0 ? $this->buildFlights() : [],
                'show_more' => $total_flights != 0 ? $this->buildShowMore() : null,
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

            // A hash nobody recorded resolves to nothing. Reading the columns
            // off it anyway built a redirect to an empty search.
            if ($search === null) {
                $this->bounce('/');

                return;
            }

            // Straight to the short form, which is what migrates every
            // `?hash=` link already out there -- the row holds components, so
            // there is no old URL to rewrite.
            $searchUrl = new SearchUrl(
                from: (string) $search[self::GET_FROM . '_code'],
                to: (string) $search[self::GET_TO . '_code'],
                depart: (string) $search[self::GET_DEPART],
                return: ($search[self::GET_RETURN] ?? null) === null
                    ? null
                    : (string) $search[self::GET_RETURN],
                // Rows recorded before the column existed default to economy,
                // which is the cabin they were all searched in.
                cabin: CabinClass::fromRequest(
                    is_string($search[self::GET_CLASS] ?? null) ? $search[self::GET_CLASS] : null,
                ),
            );

            echo new TwigRenderer()->render('search/redirect.html.twig', [
                'image_url' => Cdn::getUrl(sprintf(
                    '%s/search_redirect.gif',
                    Config::get('site.static.endpoint.images'),
                )),
                'search_url' => $searchUrl->path(),
            ]);

            die();
        }
    }

    private function searchStat(): void
    {
        // Prevent too many counts from one user: only the first page of a
        // search counts, so paging and re-filtering do not inflate it.
        if ($this->get[self::GET_SHOWN] != self::FIRST_SLICE) {
            return;
        }

        $cabin = CabinClass::fromRequest($this->get[self::GET_CLASS] ?? null);

        // The cabin is part of what identifies a search, so it is part of the
        // hash — see SearchRepository::hashFor().
        $hash = SearchRepository::hashFor(
            $this->get[self::GET_FROM],
            $this->get[self::GET_TO],
            $this->get[self::GET_DEPART],
            $this->get[self::GET_RETURN],
            $this->get[self::GET_TRIPTYPE],
            $cabin,
        );

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
            $cabin,
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
        $prefix = $this->filterPrefix();
        // Only this leg's values: the other leg's ride along in the URL but
        // must not show up as ticked boxes on this one.
        $chosen = $this->legFilterQuery($prefix);

        $codes = static fn(string $dimension): array => array_map(
            strval(...),
            (array) ($available[$dimension] ?? []),
        );

        // Cheapest itinerary carrying each option, so a row can say what
        // choosing it would cost — the single thing that turns the sidebar from
        // a set of switches into something you can shop with.
        $optionPrices = (array) json_decode((string) json_encode($this->data->option_prices ?? []), true);
        $this->optionPrices = $optionPrices;

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
                    'label' => 'All flights, one airline',
                    'hint' => 'One carrier for the whole trip, so bags are checked through.',
                    'on' => isset($chosen[FlightFilters::DIM_SINGLE_CARRIER]),
                    'available' => (bool) ($available[FlightFilters::DIM_SINGLE_CARRIER] ?? false),
                    'price' => $this->optionPrice(FlightFilters::DIM_SINGLE_CARRIER, '1'),
                ],
                FlightFilters::DIM_NO_VISA => [
                    'label' => 'No transit visa',
                    'hint' => 'Hides connections in a country that is neither your origin nor your'
                        . ' destination. Check the requirements yourself before booking.',
                    'on' => isset($chosen[FlightFilters::DIM_NO_VISA]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_VISA] ?? false),
                    'price' => $this->optionPrice(FlightFilters::DIM_NO_VISA, '1'),
                ],
                FlightFilters::DIM_NO_GULF => [
                    'label' => 'No layovers in the Gulf',
                    'hint' => 'Hides connections in the United Arab Emirates, Saudi Arabia, Qatar,'
                        . ' Kuwait, Bahrain and Oman.',
                    'on' => isset($chosen[FlightFilters::DIM_NO_GULF]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_GULF] ?? false),
                    'price' => $this->optionPrice(FlightFilters::DIM_NO_GULF, '1'),
                ],
                FlightFilters::DIM_NO_NIGHT => [
                    'label' => 'No overnight layovers',
                    'hint' => 'Hides connections spent waiting between 23:00 and 06:00.',
                    'on' => isset($chosen[FlightFilters::DIM_NO_NIGHT]),
                    'available' => (bool) ($available[FlightFilters::DIM_NO_NIGHT] ?? false),
                    'price' => $this->optionPrice(FlightFilters::DIM_NO_NIGHT, '1'),
                ],
            ],
            'ranges' => [
                FlightFilters::DIM_LAYOVER_RANGE => $this->rangeOption(
                    $bounds[FlightFilters::DIM_LAYOVER_RANGE] ?? null,
                    $chosen[FlightFilters::DIM_LAYOVER_RANGE] ?? null,
                    self::DURATION_STEPS,
                    'minutes',
                ),
            ],
            'sliders' => [
                FlightFilters::DIM_PRICE => $this->sliderOption(
                    $bounds[FlightFilters::DIM_PRICE] ?? null,
                    $chosen[FlightFilters::DIM_PRICE] ?? null,
                    self::PRICE_STEPS,
                    'money',
                ),
                FlightFilters::DIM_DURATION => $this->sliderOption(
                    $bounds[FlightFilters::DIM_DURATION] ?? null,
                    $chosen[FlightFilters::DIM_DURATION] ?? null,
                    self::DURATION_STEPS,
                    'minutes',
                ),
            ],
            // Which groups hold something the visitor has set, so a filter is
            // never left hidden behind a collapsed heading.
            'active' => $this->activeSections($chosen),
            // The prefix the controls submit under, so each leg writes its own.
            'prefix' => $prefix,
            // Where this leg starts and ends. Reversed on the return, so the
            // time filters name the airports they actually apply to rather than
            // the ones the search was typed with.
            'leg' => [
                'from' => $prefix === FlightFilters::RETURN_PREFIX
                    ? (string) $this->data->arrive_city_name
                    : (string) $this->data->depart_city_name,
                'to' => $prefix === FlightFilters::RETURN_PREFIX
                    ? (string) $this->data->depart_city_name
                    : (string) $this->data->arrive_city_name,
            ],
            // What the leg you are not looking at is filtered by.
            'other_leg' => $this->otherLegNote($prefix),
            // Whether this leg is filtered, so the sidebar can offer a way out.
            'any_applied' => !FlightFilters::fromQuery($this->get, $prefix)->isEmpty(),
            'clear_url' => $this->clearFiltersUrl($prefix),
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
                if (($chosen[$key] ?? null) !== null) {
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

        return is_numeric($price) ? $this->presenter()->priceParts((float) $price) : null;
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

        $picked = $this->selected($chosen, FlightFilters::DIM_AIRCRAFT);
        $types = new AircraftRepository($this->connection())->all();
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
        $picked = $this->selected($chosen, FlightFilters::DIM_ARRIVE_DATE);
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
        $picked = array_map(strtolower(...), FlightFilters::values($chosen[$key] ?? null));
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
    private function sliderOption(?array $bound, mixed $value, array $steps, string $kind): ?array
    {
        if ($bound === null) {
            return null;
        }

        $chosen = is_numeric($value) ? (int) $value : null;

        // Nothing to drag between when every option costs or lasts the same —
        // unless a ceiling is set, in which case the control has to stay: it is
        // the way back out, and a form missing the input drops the filter
        // without saying so.
        if ($bound['max'] <= $bound['min'] && $chosen === null) {
            return null;
        }

        // The ends have to contain the chosen ceiling as well as what is on
        // offer, or the handle gets clamped somewhere nobody asked for.
        $low = $bound['min'];
        $high = $chosen === null ? $bound['max'] : max($bound['max'], $chosen);

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
        if ($chosen !== null && $chosen < $min) {
            $min = $chosen;
        }

        $value = $chosen === null ? $max : max($min, min($chosen, $max));

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
     * Same rounding as a single slider, but both handles matter: a layover
     * range rules out connections that are too tight as well as too long.
     *
     * @param array{min: int, max: int, floor_max: int, ceiling_min: int}|null $bound
     * @param list<int> $steps
     * @return array<string, mixed>|null
     */
    private function rangeOption(?array $bound, mixed $value, array $steps, string $kind): ?array
    {
        if ($bound === null) {
            return null;
        }

        // Every form FlightFilters accepts, so the control shows the state the
        // filter actually applied — a slider reading "Any" over a filtered page
        // would drop the filter on the next Apply. A bare number is a ceiling
        // with no floor under it, and the floor handle rests at the bottom.
        $chosen = null;

        if (is_string($value) && preg_match('/^(\d{1,5})$/', $value, $match) === 1) {
            $chosen = [null, (int) $match[1]];
        } elseif (is_string($value) && preg_match('/^(\d{1,5})[;-](\d{1,5})$/', $value, $match) === 1) {
            $chosen = [(int) $match[1], (int) $match[2]];
        }

        // Nothing left to choose between and nothing chosen: no control worth
        // drawing. With a filter applied it has to stay whatever the spread,
        // since hiding it is the one way out of a narrow filter — and a form
        // missing the input drops that filter without saying so.
        if ($bound['max'] <= $bound['min'] && $chosen === null) {
            return null;
        }

        // The ends have to contain the chosen range as well as what is on
        // offer, or the handles get clamped to somewhere the user never asked
        // for.
        $low = $chosen === null || $chosen[0] === null
            ? $bound['min']
            : min($bound['min'], $chosen[0]);
        $high = $chosen === null ? $bound['max'] : max($bound['max'], $chosen[1]);

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

        if ($chosen !== null) {
            $from = $chosen[0] === null ? $min : max($min, min($chosen[0], $max));
            $to = max($from, min($chosen[1], $max));
        }

        // How far each handle may travel. Beyond these the results are empty
        // whatever the other handle says, and a stretch of track that can only
        // return nothing is a promise the search cannot keep. Snapped onto the
        // step grid, since that is where a handle can actually land, and
        // widened to admit a range already applied so the control can always
        // show the state it is in.
        $floorMax = min($max, $min + (int) floor(($bound['floor_max'] - $min) / $step) * $step);
        $ceilingMin = max($min, $min + (int) ceil(($bound['ceiling_min'] - $min) / $step) * $step);

        if ($chosen !== null) {
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

        foreach (FlightFilters::queryKeys($prefix) as $key) {
            $kept[$key] = null;
        }

        return $this->link(array_merge($kept, [self::GET_SHOWN => null]));
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
                // The one already chosen, when a Change link sent us back here.
                'is_current' => $built['ids'] === $this->currentIds(),
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
     * @param list<int>|null $current leg ids this link is replacing, if any
     */
    private function stepUrl(?array $ids, bool $keepReturn = false, ?array $current = null): string
    {
        return $this->link(array_merge($this->get, [
            self::GET_DEPART_ITIN => $ids === null ? null : implode(',', $ids),
            self::GET_RETURN_ITIN => $keepReturn ? ($this->get[self::GET_RETURN_ITIN] ?? null) : null,
            // Only a Change link carries this; choosing from the list does
            // not, or the marker would follow the traveller forward.
            self::GET_CURRENT => $current === null || $current === [] ? null : implode(',', $current),
            self::GET_SHOWN => null,
        ]));
    }

    /**
     * URL that adds the chosen return to the package, keeping the outbound.
     *
     * @param list<int> $ids
     */
    private function returnStepUrl(array $ids): string
    {
        return $this->link(array_merge($this->get, [
            self::GET_RETURN_ITIN => implode(',', $ids),
            self::GET_SHOWN => null,
        ]));
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
     * The fare rules for each half of an assembled trip.
     *
     * @return list<array{leg: string, title: string, lines: list<array{text: string, allowed: bool}>}>
     */
    private function includedRules(): array
    {
        $flights = new FlightRepository($this->connection());
        $brands = new FareBrandRepository($this->connection());

        $halves = [
            'Departing' => array_map(intval(...), (array) $this->data->selected_ids),
            'Returning' => array_map(intval(...), (array) $this->data->selected_return_ids),
        ];

        $out = [];

        foreach ($halves as $leg => $ids) {
            if ($ids === []) {
                continue;
            }

            $rules = $brands->rulesFor($flights->fareBrandsByIds($ids));

            if ($rules === null) {
                continue;
            }

            $out[] = ['leg' => $leg, 'title' => $rules->title, 'lines' => $rules->lines()];
        }

        return $out;
    }

    /**
     * The "show more" control: where the next slice comes from and how much of
     * the result is still unseen.
     *
     * A real URL rather than a JS-only button, so the list still grows without
     * scripting and the link is something a crawler can follow.
     *
     * @return array{url: string|null, from: int, next: int, remaining: int}|null
     */
    private function buildShowMore(): ?array
    {
        $shown = (int) $this->get[self::GET_SHOWN];
        $total = (int) $this->data->total_flights;

        if (!$this->data->has_more) {
            return null;
        }

        // At the cap there is more to see but no more to append. Say so and
        // name the way through — silently ending the list looks like the
        // search ran out, and the visitor would have no reason to filter.
        if ($shown >= self::MAX_SHOWN) {
            return ['url' => null, 'from' => $shown, 'next' => $shown, 'remaining' => $total - $shown];
        }

        $next = min($shown + self::NEXT_SLICE, self::MAX_SHOWN, $total);

        return [
            'url' => $this->moreUrl($next),
            'from' => $shown,
            'next' => $next,
            'remaining' => $total - $shown,
        ];
    }

    private function moreUrl(int $shown): string
    {
        return $this->link(array_merge($this->get, [self::GET_SHOWN => $shown]));
    }

    /**
     * Where a fragment request should start reading.
     *
     * Named `after` rather than `from`, which the search already uses for the
     * departure airport — a second `from` in the query string overwrites it and
     * the search collapses to nothing.
     *
     * `fragment` and `after` are read straight from the request rather than
     * kept in $this->get: everything in there is rebuilt into the page's own
     * links, and a stray `fragment=1` on a sort tab would answer with bare
     * cards.
     */
    private function fragmentFrom(int $shown): int
    {
        if (!$this->isFragment()) {
            return 0;
        }

        $after = $this->request->query->intWithin('after', 0, 0, self::MAX_SHOWN);

        return min($after, $shown);
    }

    private function isFragment(): bool
    {
        return $this->request->isFragment();
    }

    /**
     * Where this page lives.
     *
     * Every link this controller builds is this same page with a different
     * query string, so they take the canonical path rather than the one the
     * visitor happened to arrive on. Otherwise reaching `/search` renders links
     * to `/search` while the form above them posts to `/search/` -- the same
     * destination spelled two ways on one screen.
     */
    /**
     * Leg ids of the flight a Change link is replacing, if any.
     *
     * @return list<int>
     */
    private function currentIds(): array
    {
        return new Input($this->get)->ids(self::GET_CURRENT);
    }

    /**
     * The search as a path segment, so every URL this page builds carries it
     * instead of six query parameters.
     */
    /**
     * The party as a query fragment for the checkout links, empty for a lone
     * adult so the common URL stays clean.
     */
    private function checkoutPax(): string
    {
        $party = $this->searchUrl->party();
        $query = array_filter([
            'adults' => $party->adults > 1 ? $party->adults : null,
            'children' => $party->children ?: null,
            'infants' => $party->infants ?: null,
        ]);

        return $query === [] ? '' : '&' . http_build_query($query);
    }

    private function searchPath(): string
    {
        return $this->searchUrl?->path() ?? (string) Config::get('site.paths.search', '/search/');
    }

    /**
     * Where an old-format link should have landed: the short path, keeping
     * whatever filtering and sorting rode along with it.
     */
    /**
     * A link to this search, with whatever is being changed applied on top.
     *
     * The `?` is conditional because it has to be: with the search itself in
     * the path, a plain unsorted unfiltered result has nothing left to put in a
     * query string, and every one of these used to end in a bare `?`.
     *
     * @param array<string, mixed> $params
     */
    private function link(array $params): string
    {
        $query = $this->queryString($params);

        return $this->searchPath() . ($query === '' ? '' : '?' . $query);
    }

    /**
     * The six keys the path now carries. Written back into $this->get so the
     * rest of the page -- the form, the template, the stat row -- keeps reading
     * them from one place.
     *
     * @return array<string, string|null>
     */
    private function identity(): array
    {
        return [
            self::GET_FROM => $this->searchUrl->from,
            self::GET_TO => $this->searchUrl->to,
            self::GET_DEPART => $this->searchUrl->depart,
            self::GET_RETURN => $this->searchUrl->return,
            self::GET_TRIPTYPE => $this->searchUrl->tripType()->value,
            self::GET_CLASS => $this->searchUrl->cabin->value,
        ];
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
        foreach ([...self::PATH_KEYS, self::GET_HASH] as $key) {
            unset($params[$key]);
        }

        // The first slice is what you get without asking, so saying so adds
        // nothing but noise to a canonical URL. A larger one is a real
        // instruction and stays.
        if ((int) ($params[self::GET_SHOWN] ?? 0) === self::FIRST_SLICE) {
            unset($params[self::GET_SHOWN]);
        }

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
        // The identity keys are in the form's action path now. Leaving them as
        // hidden inputs would put them back in the query string on every Apply,
        // and the request would redirect straight back here.
        $drop = [self::GET_SHOWN, self::GET_HASH, ...self::PATH_KEYS, ...$without];

        return array_filter(
            $this->get,
            static fn(mixed $v, string $k): bool => $v !== null && $v !== '' && $v !== []
                && !in_array($k, $drop, true),
            ARRAY_FILTER_USE_BOTH,
        );
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
        $step = $this->data?->step;

        // Only a round trip mid-choice has another leg to speak of: a one-way
        // search has none, and step 3 lists nothing to filter.
        if ($step === null || $step === 3) {
            return null;
        }

        $otherPrefix = $prefix === '' ? FlightFilters::RETURN_PREFIX : '';
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
            $own[$key] = $this->get[$prefix . $key] ?? null;
        }

        return $own;
    }

    /**
     * The sort options as tabs, each carrying the price and travel time it
     * would put first.
     *
     * Primary ones lead; the rest follow in a dropdown, because seven tabs is a
     * list, not a choice.
     *
     * @return array{primary: list<array<string, mixed>>, more: list<array<string, mixed>>, current: string}
     */
    private function sortTabs(): array
    {
        $highlights = (array) json_decode((string) json_encode($this->data->highlights ?? []), true);
        $current = $this->sort();
        $triptype = $this->get[self::GET_TRIPTYPE];

        $primary = [];
        $more = [];

        /** @var array<string, array<string, mixed>> $options */
        $options = (array) Config::get('search.sort', []);

        foreach ($options as $key => $params) {
            // Some sorts only make sense one way round (arriving early says
            // nothing about a trip you have not chosen the return for yet).
            if (($params[$triptype] ?? 1) != 1) {
                continue;
            }

            $best = $highlights[(string) $key] ?? null;

            $tab = [
                'key' => (string) $key,
                'title' => (string) ($params['tab_title'] ?? $params['title']),
                'note' => (string) $params['note'],
                'icon' => (string) ($params['icon'] ?? 'fa-sort'),
                'current' => (string) $key === $current,
                'url' => $this->sortUrl((string) $key),
                'price' => $best === null ? null : $this->presenter()->priceParts((float) $best['price']),
                // Hours, never days: the whole point of the bar is comparing
                // these three side by side, and "1d 5h" against "21h 56m" is a
                // sum the reader has to do. 29h 12m against 21h 56m is not.
                'duration' => $best === null ? null : Helper::hoursAndMinutes((int) $best['duration']),
            ];

            if (in_array((string) $key, self::PRIMARY_SORTS, true)) {
                $primary[] = $tab;
            } else {
                $more[] = $tab;
            }
        }

        return ['primary' => $primary, 'more' => $more, 'current' => $current];
    }

    /**
     * A duration in hours and minutes, without rolling over into days.
     */
    /**
     * This search, sorted differently. Page one, since the order changed.
     */
    private function sortUrl(string $sort): string
    {
        return $this->link(array_merge($this->get, [
            self::GET_SORT => $sort === self::DEFAULT_SORT ? null : $sort,
            self::GET_SHOWN => null,
        ]));
    }

    /**
     * Which leg's filters the sidebar is currently editing.
     *
     * Step 2 lists the return, so its controls belong to the return's set.
     * Everywhere else — step 1, step 3 and a one-way search — the outbound set
     * is the one on show.
     */
    private function filterPrefix(): string
    {
        return $this->data?->step === 2 ? FlightFilters::RETURN_PREFIX : '';
    }

    /**
     * Every filter key for both legs.
     *
     * @return list<string>
     */
    private function allFilterKeys(): array
    {
        return [
            ...FlightFilters::queryKeys(),
            ...FlightFilters::queryKeys(FlightFilters::RETURN_PREFIX),
        ];
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

        foreach ($this->allFilterKeys() as $key) {
            $value = $this->request->query->raw($key);

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

        // Anything unrecognised resolves to the default, so the tab strip
        // agrees with the order the results actually came back in — and a
        // mangled value is not carried on into every link on the page.
        return is_string($sort) && SortMethod::tryFrom($sort) !== null ? $sort : self::DEFAULT_SORT;
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
