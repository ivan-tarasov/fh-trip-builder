<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use RuntimeException;
use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\Input;
use TripBuilder\Http\RateLimit;
use TripBuilder\Log;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\FareBrandRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\RouteAddress;
use TripBuilder\SearchUrl;
use TripBuilder\Service\FlightFinder;
use TripBuilder\TripType;
use TripBuilder\View\ItineraryPresenter;
use TripBuilder\View\RecentSearches;
use TripBuilder\View\SearchFilterPanel;
use TripBuilder\View\TwigRenderer;

/**
 * @phpstan-import-type ResponseSearch from FlightFinder
 *
 * `hash`, `depart`, `return`, `depart_itin`, `return_itin`, `current` and
 * `sort` are absent-means-absent (`Input::nullableStr()`), so they stay
 * `string|null` for as long as the property lives. `from` and `to` are
 * never null -- `Input::str()` always returns a string, and `identity()`
 * only ever narrows them further. `triptype` is normalised to a real
 * `TripType`'s value inside `setGet()` itself before the property is ever
 * written, so it is the one key the property declares non-nullable even
 * though the raw value handed to `setGet()` can be null. `class` stays
 * nullable rather than normalised the same way, because every reader of it
 * already calls `CabinClass::fromRequest()`, which takes `?string` and
 * supplies its own default -- normalising here would just be a second
 * place that default could drift from.
 *
 * @phpstan-type SearchGet array{
 *     hash: string|null, from: string, to: string,
 *     depart: string|null, return: string|null,
 *     triptype: string, class: string|null, shown: int,
 *     depart_itin: string|null, return_itin: string|null,
 *     current: string|null, sort: string|null,
 *     ...
 * }
 * @phpstan-type SearchGetRaw array{
 *     hash: string|null, from: string, to: string,
 *     depart: string|null, return: string|null,
 *     triptype: string|null, class: string|null, shown: int,
 *     depart_itin: string|null, return_itin: string|null,
 *     current: string|null, sort: string|null,
 *     ...
 * }
 */
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
    //
    // Constants, and not config. `site.pagination` held 'search' => 7 and
    // 'booking' => 100 and nothing ever read either: the page has always shown
    // ten, and the bookings list has no limit at all. Both are gone. A number
    // in config that disagrees with the code is worse than no config, because
    // it is the one somebody edits.
    private const int FIRST_SLICE = 10;
    private const int NEXT_SLICE = 20;
    private const int MAX_SHOWN = 210;

    // The three that earn a tab of their own; the rest sit in the dropdown.
    private const array PRIMARY_SORTS = ['recommended', 'price', 'duration'];

    /** @var SearchGet */
    private array $get;

    /** @var ResponseSearch|null */
    private ?array $data = null;

    private ?ItineraryPresenter $presenter = null;

    private ?SearchUrl $searchUrl = null;

    private ?SearchFilterPanel $filterPanel = null;

    public function index(): void
    {
        try {
            $query = $this->request->query;

            // Handle GET data
            $known = [
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
            ];

            // Merged as its own step rather than inside the literal above --
            // spreading filterQuery()'s generically-typed return into that
            // literal would widen the whole thing to a generic array and lose
            // the shape phpstan just built.
            /** @var SearchGetRaw $get */
            $get = [...$known, ...$this->filterQuery()];

            $this->setGet($get);

            // Convert search hash to url and redirect
            if ($this->checkHash()) {
                return;
            }

            // The search itself comes from the path when there is one, and from
            // the query string when the link predates it.
            $this->searchUrl = SearchUrl::parse($this->request->path())
                ?? SearchUrl::fromQuery($query);

            // The route matched, so the URL is well formed; what it names is
            // not. A date the calendar does not have, a window wider than the
            // search will run, a return before its departure -- each is a page
            // that cannot exist, and saying so in the status is what keeps it
            // out of an index. The old answer was a 200 carrying an inline
            // script, which only moved a visitor who ran JavaScript and whose
            // content policy allowed it.
            if ($this->searchUrl === null) {
                $this->notFound();

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
            if ($this->request->path() !== $this->searchUrl()->path() && !$this->request->isFragment()) {
                $this->bounce($this->link($this->get), HttpStatus::MovedPermanently);

                return;
            }

            $shown = $this->get[self::GET_SHOWN];
            // A fragment request already holds everything above `from`, so it
            // asks only for the part it is missing.
            $from = $this->fragmentFrom($shown);

            $query = new FlightSearchQuery(
                offset: $from,
                limit: $shown - $from,
                sort: $this->sort(),
                from: $this->get[self::GET_FROM],
                to: $this->get[self::GET_TO],
                departDate: $this->get[self::GET_DEPART] ?? '',
                returnDate: $this->get[self::GET_RETURN] ?? '',
                party: $this->searchUrl()->party(),
                departSpan: $this->searchUrl()->departSpan,
                returnSpan: $this->searchUrl()->returnSpan,
                cabin: CabinClass::fromRequest($this->get[self::GET_CLASS] ?? null),
                filters: FlightFilters::fromQuery($this->get, party: $this->searchUrl()->party()),
                returnFilters: FlightFilters::fromQuery(
                    $this->get,
                    FlightFilters::RETURN_PREFIX,
                    $this->searchUrl()->party(),
                ),
            );

            $payload = new FlightFinder($this->connection())->search(
                $query,
                TripType::from($this->get[self::GET_TRIPTYPE]),
                $this->parseIds((string) ($this->get[self::GET_DEPART_ITIN] ?? '')),
                $this->parseIds((string) ($this->get[self::GET_RETURN_ITIN] ?? '')),
            );

            $this->setData($payload);

            // Recording search stat
            $this->searchStat();

            $total_flights = $this->data()['total_flights'];

            // "Load more" asks for cards, not a page: same query, same filters,
            // same sort — only the window differs. Rendering the one partial
            // the full page uses keeps the two from drifting apart.
            $cabin = CabinClass::fromRequest($this->get[self::GET_CLASS] ?? null);

            if ($this->isFragment()) {
                echo new TwigRenderer()->render('search/cards/list.html.twig', [
                    'flights' => $total_flights != 0 ? $this->buildFlights() : [],
                    'step' => $this->data()['step'],
                    'price_mode' => $this->data()['price_mode'],
                    'show_more' => $total_flights != 0 ? $this->buildShowMore() : null,
                    // The cards link to checkout, and that link carries the
                    // cabin — so an appended card needs it as much as a
                    // first-paint one.
                    'cabin' => $cabin,
                ]);

                return;
            }

            $places = new AirportRepository($this->connection())->pickable();

            echo new TwigRenderer()->renderPage('search/view.html.twig', [
                // So the form above the results shows the party that was
                // searched for rather than resetting to one adult.
                'party' => $this->searchUrl()->party(),
                'party_label' => $this->searchUrl()->party()->label(),
                // Carried onto the checkout links so the party survives the hop
                // -- the legs say what is being bought, not for how many.
                'checkout_pax' => $this->checkoutPax(),
                // Lead form + sidebar + cards share the resolved query context.
                'triptype' => $this->get[self::GET_TRIPTYPE],
                'depart_code' => AirportRepository::pickableCodeFor($places, $this->get[self::GET_FROM]),
                'arrive_code' => AirportRepository::pickableCodeFor($places, $this->get[self::GET_TO]),
                // So the results page's own form comes back showing the cabin
                // that produced these results.
                'cabin' => $cabin,
                'places' => $places,
                // Where else somebody could fly from or into, keyed by the code
                // the field holds. Both fields have one here, which is what
                // makes this the page the block is worth drawing on.
                'nearby' => $this->nearbyPlaces($places),
                'recent' => RecentSearches::rows($this->request->cookies, $places),
                'depart_city' => $this->data()['depart'],
                'arrive_city' => $this->data()['arrive'],
                // Only when a nonstop one actually exists for this exact
                // pair -- most do (RouteController's own docblock counts
                // five exceptions out of 163 real searched pairs), but
                // never a link the route page would 404 on.
                'route_url' => $this->routeUrlFor($this->get[self::GET_FROM], $this->get[self::GET_TO]),
                'depart_date' => $this->get[self::GET_DEPART],
                'return_date' => $this->get[self::GET_RETURN],
                'depart_flex' => $this->searchUrl()->departSpan,
                'return_flex' => $this->searchUrl()->returnSpan,
                // Filter forms submit with GET, so they post to the search's
                // own path and carry only the rest -- sort and filters -- as
                // hidden fields. The search itself is in that path now.
                'form_path' => $this->searchPath(),
                'session_sort' => $this->sort(),
                'default_sort' => self::DEFAULT_SORT,
                // Sorting moved out of the sidebar and above the results, where
                // each option can show what choosing it would get you.
                'sort_tabs' => $this->sortTabs(),
                'sidebar' => $this->filterPanel()->build(),
                // What the sidebar needs to draw itself: the filters currently
                // applied, and which options are worth offering at all.
                'filters' => $this->filterQuery(),
                'available' => $this->data()['available'],
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
                'step' => $this->data()['step'],
                'step_title' => $this->stepTitle(),
                'step_route' => $this->stepRoute(),
                'step_date' => $this->data()['step'] === 2
                    ? $this->get[self::GET_RETURN]
                    : $this->get[self::GET_DEPART],
                // The last day the search covers. A flexible search draws its
                // cards from up to three days, and the header named only the
                // first of them -- so a page of results dated the 17th sat
                // under a line that said the 15th.
                'step_date_until' => $this->data()['step'] === 2
                    ? $this->searchUrl()->returnUntil()
                    : $this->searchUrl()->departUntil(),
                'price_mode' => $this->data()['price_mode'],
                // What the "Watch this route" form suggests as a threshold --
                // the same signpost role `place.cheapest` plays on the route
                // page, not a promise this exact price will recur.
                'cheapest_total' => $this->data()['cheapest_total'],
                'selected' => $this->data()['selected'] === null
                    ? null
                    : $this->presenter()->direction($this->data()['selected'])['direction'],
                'selected_price' => $this->data()['selected_price'] === null
                    ? null
                    : $this->presenter()->priceParts((float) $this->data()['selected_price']),
                'selected_return' => $this->data()['selected_return'] === null
                    ? null
                    : $this->presenter()->direction($this->data()['selected_return'])['direction'],
                'selected_return_price' => $this->data()['selected_return_price'] === null
                    ? null
                    : $this->presenter()->priceParts((float) $this->data()['selected_return_price']),
                'package_price' => $this->data()['package_price'] === null
                    ? null
                    : $this->presenter()->priceParts((float) $this->data()['package_price']),
                // What the ticket allows, folded to the strictest leg of each
                // direction. Baggage is the commonest reason a trip gets
                // abandoned at payment, so it belongs on the page where the
                // flights can still be swapped rather than only on the one
                // where a form has to be filled in first.
                'included' => $this->data()['step'] === 3 ? $this->includedRules() : null,
                'package_ids' => [
                    'outbound' => implode(',', array_map(intval(...), $this->data()['selected_ids'])),
                    'return' => implode(',', array_map(intval(...), $this->data()['selected_return_ids'])),
                ],
                'depart_date_label' => $this->get[self::GET_DEPART],
                'return_date_label' => $this->get[self::GET_RETURN],
                // Changing one half keeps the other, so the traveller returns
                // straight to the package once they have re-picked.
                'change_url' => $this->stepUrl(
                    null,
                    keepReturn: true,
                    current: array_values(array_map(intval(...), $this->data()['selected_ids'])),
                ),
                'change_return_url' => $this->stepUrl(
                    array_values(array_map(intval(...), $this->data()['selected_ids'])),
                    keepReturn: false,
                    current: array_values(array_map(intval(...), $this->data()['selected_return_ids'])),
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
            Log::error('Search page failed: ' . $e->getMessage());
            echo 'Something went wrong while searching for flights. Please try again later.';
        }
    }

    /**
     * Answer a `?hash=` link, if this is one.
     *
     * True when the request has been answered and nothing after it should run.
     * It used to redirect and then fall through, so a 302 left here carrying a
     * whole page body behind it -- and, once an unreachable search started
     * answering 404, a status that overwrote the redirect.
     *
     * @throws Exception|\Twig\Error\Error
     */
    private function checkHash(): bool
    {
        if ($this->get['hash']) {
            $search = new SearchRepository($this->connection())->findByHash($this->get['hash']);

            // A hash nobody recorded resolves to nothing. Reading the columns
            // off it anyway built a redirect to an empty search.
            if ($search === null) {
                $this->bounce('/');

                return true;
            }

            // Straight to the short form, which is what migrates every
            // `?hash=` link already out there -- the row holds components, so
            // there is no old URL to rewrite.
            $searchUrl = new SearchUrl(
                from: $search[self::GET_FROM . '_code'],
                to: $search[self::GET_TO . '_code'],
                depart: $search[self::GET_DEPART],
                return: $search[self::GET_RETURN],
                // Rows recorded before the column existed default to economy,
                // which is the cabin they were all searched in.
                cabin: CabinClass::fromRequest($search[self::GET_CLASS]),
                // Rows written before flexible dates have no span columns to
                // read, so they rebuild as the single-date searches they were.
                departSpan: max(1, $search['depart_span']),
                returnSpan: max(1, $search['return_span']),
            );

            echo new TwigRenderer()->render('search/redirect.html.twig', [
                'image_url' => Cdn::getUrl(sprintf(
                    '%s/search_redirect.gif',
                    Config::get('site.static.endpoint.images'),
                )),
                'search_url' => $searchUrl->path(),
            ]);

            // `true` and not `die()`: the sibling branch above already returns
            // it and index() already returns on it, so the exit was only ever
            // skipping the shutdown functions (E10.5, #198). The template
            // renders a whole document, so the layout is not added on the way
            // out.
            return true;
        }

        return false;
    }

    private function searchStat(): void
    {
        // Prevent too many counts from one user: only the first page of a
        // search counts, so paging and re-filtering do not inflate it.
        if ($this->get[self::GET_SHOWN] != self::FIRST_SLICE) {
            return;
        }

        // Guards the write below, not the search itself -- an over-the-limit
        // client still sees its results, the count just stops moving.
        if ($this->isOverLimit(RateLimit::Search)) {
            return;
        }

        $cabin = CabinClass::fromRequest($this->get[self::GET_CLASS] ?? null);

        // The cabin is part of what identifies a search, so it is part of the
        // hash — see SearchRepository::hashFor().
        $hash = SearchRepository::hashFor(
            $this->get[self::GET_FROM],
            $this->get[self::GET_TO],
            $this->get[self::GET_DEPART] ?? '',
            $this->get[self::GET_RETURN],
            $this->get[self::GET_TRIPTYPE],
            $cabin,
            $this->searchUrl()->departSpan,
            $this->searchUrl()->returnSpan,
        );

        // Offered back in the origin and destination fields next time. Kept in
        // this browser rather than in the `search` table, which counts how
        // popular a route is and has no column for who ran it.
        RecentSearches::remember($this->request->cookies, $this->searchUrl(), $this->request->isSecure());

        // Insert or update search
        new SearchRepository($this->connection())->record(
            $hash,
            $this->get[self::GET_FROM],
            trim(preg_replace('/\([^)]+\)/', '', $this->data()['depart']) ?? $this->data()['depart']),
            $this->get[self::GET_TO],
            trim(preg_replace('/\([^)]+\)/', '', $this->data()['arrive']) ?? $this->data()['arrive']),
            $this->get[self::GET_DEPART] ?? '',
            $this->get[self::GET_RETURN],
            $this->get[self::GET_TRIPTYPE],
            $cabin,
            $this->searchUrl()->departSpan,
            $this->searchUrl()->returnSpan,
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

        $step = $this->data()['step'];
        $cheapest = $this->data()['cheapest_total'];
        // Naming one option "cheapest" only says something when there is more
        // than one to be cheaper than.
        $compare = $cheapest !== null && $this->data()['total_flights'] > 1;

        foreach ($this->data()['flights'] as $flight) {
            $built = $this->presenter()->direction($flight['itinerary']);
            $total = $flight['price_base'] + $flight['price_tax'];
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
                    ? $this->presenter()->priceRounded($difference)
                    : null,
                'price_base' => $this->presenter()->priceParts($flight['price_base']),
                'price_tax' => $this->presenter()->priceParts($flight['price_tax']),
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
        return match ($this->data()['step']) {
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
        return match ($this->data()['step']) {
            2 => sprintf('%s → %s', $this->data()['arrive'], $this->data()['depart']),
            3 => sprintf('%s ⇄ %s', $this->data()['depart'], $this->data()['arrive']),
            default => sprintf('%s → %s', $this->data()['depart'], $this->data()['arrive']),
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
            'Departing' => array_map(intval(...), $this->data()['selected_ids']),
            'Returning' => array_map(intval(...), $this->data()['selected_return_ids']),
        ];

        $out = [];

        foreach ($halves as $leg => $ids) {
            if ($ids === []) {
                continue;
            }

            $rules = $brands->rulesFor($flights->fareBrandsByIds(array_values($ids)));

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
        $total = (int) $this->data()['total_flights'];

        if (!$this->data()['has_more']) {
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
     * Leg ids of the flight a Change link is replacing, if any.
     *
     * @return list<int>
     */
    private function currentIds(): array
    {
        return new Input($this->get)->ids(self::GET_CURRENT);
    }

    /**
     * The party as a query fragment for the checkout links, empty for a lone
     * adult so the common URL stays clean.
     */
    private function checkoutPax(): string
    {
        $party = $this->searchUrl()->party();
        $query = array_filter([
            'adults' => $party->adults > 1 ? $party->adults : null,
            'children' => $party->children ?: null,
            'infants' => $party->infants ?: null,
        ]);

        return $query === [] ? '' : '&' . http_build_query($query);
    }

    /**
     * The route page for this exact pair, only when a nonstop one exists to
     * link to.
     *
     * `RouteController` answers a pair with only connecting itineraries
     * with a 404 -- checked here with its own `summary()`, the same way it
     * checks, so the search results never draw a link the route page would
     * refuse. A search for two places already the same city (their airport
     * sets overlap) gets no link either, the same reasoning
     * `RouteController::show()` gives its own "a route to where you already
     * are is not a route" guard.
     */
    private function routeUrlFor(string $fromCode, string $toCode): ?string
    {
        $airports = new AirportRepository($this->connection());
        $origins = $airports->codesFor($fromCode);
        $destinations = $airports->codesFor($toCode);

        if ($origins === [] || $destinations === [] || array_intersect($origins, $destinations) !== []) {
            return null;
        }

        $summary = new RouteRepository($this->connection())->summary($origins, $destinations, CabinClass::Economy);

        if ($summary === null) {
            return null;
        }

        // The canonical grouped name, not the specific airport's own city
        // label -- RouteAddress::path() built from EWR's own "Newark"
        // spells a slug RouteAddress::index() never indexed, since that
        // city's real page is "New York" to Tokyo's own group.
        $fromName = $airports->canonicalCityByCode($fromCode);
        $toName = $airports->canonicalCityByCode($toCode);

        return $fromName === null || $toName === null ? null : RouteAddress::path($fromName, $toName);
    }

    /**
     * The nearby block's rows for each field, keyed by the code that field
     * holds.
     *
     * Two lookups rather than one: the answer differs per field, and the
     * template picks by the field's own value. Composed in the repository --
     * see AirportRepository::nearbyPlaces() -- so neither the distance nor the
     * city grouping is defined a second time here.
     *
     * @param list<array<string, mixed>> $places
     * @return array<string, list<string>>
     */
    private function nearbyPlaces(array $places): array
    {
        $repository = new AirportRepository($this->connection());
        $nearby = [];

        foreach ([$this->get[self::GET_FROM], $this->get[self::GET_TO]] as $code) {
            $code = (string) $code;

            if ($code === '' || isset($nearby[$code])) {
                continue;
            }

            $rows = $repository->nearbyPlaces(
                $code,
                AirportRepository::NEARBY_CITIES,
                AirportRepository::NEARBY_KM,
            );

            if ($rows !== []) {
                $nearby[$code] = $rows;
            }
        }

        return $nearby;
    }

    /**
     * Where this page lives: the search as a path segment, so every URL this
     * page builds carries it instead of six query parameters.
     *
     * Every link this controller builds is this same page with a different
     * query string, so they take the canonical path rather than the one the
     * visitor happened to arrive on. Otherwise reaching `/search` renders links
     * to `/search` while the form above them posts to `/search/` -- the same
     * destination spelled two ways on one screen.
     */
    private function searchPath(): string
    {
        return $this->searchUrl?->path() ?? (string) Config::get('site.paths.search', '/search/');
    }

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
     * @return array{
     *     from: string, to: string, depart: string, return: string|null,
     *     triptype: string, class: string,
     * }
     */
    private function identity(): array
    {
        return [
            self::GET_FROM => $this->searchUrl()->from,
            self::GET_TO => $this->searchUrl()->to,
            self::GET_DEPART => $this->searchUrl()->depart,
            self::GET_RETURN => $this->searchUrl()->return,
            self::GET_TRIPTYPE => $this->searchUrl()->tripType()->value,
            self::GET_CLASS => $this->searchUrl()->cabin->value,
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
        $shown = $params[self::GET_SHOWN] ?? null;

        if (is_int($shown) && $shown === self::FIRST_SLICE) {
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
        // Dropped by unset rather than left to the $drop check below: `shown`
        // is the one key in $this->get that is not string|list<string>, and
        // only unset lets phpstan see it is gone rather than merely possibly
        // filtered.
        $get = $this->get;
        unset($get[self::GET_SHOWN]);

        // The identity keys are in the form's action path now. Leaving them as
        // hidden inputs would put them back in the query string on every Apply,
        // and the request would redirect straight back here.
        $drop = [self::GET_HASH, ...self::PATH_KEYS, ...$without];

        return array_filter(
            $get,
            static fn(mixed $v, string $k): bool => $v !== null && $v !== '' && $v !== []
                && !in_array($k, $drop, true),
            ARRAY_FILTER_USE_BOTH,
        );
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
        $highlights = $this->data()['highlights'];
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
        return FlightFilters::prefixFor($this->data['step'] ?? null);
    }

    /**
     * The sidebar, which builds itself (E27, #200).
     *
     * The closure is the one thing it cannot work out alone: a URL for this
     * search lives in the path, and only the controller holds the SearchUrl
     * that spells it. Paging is reset here rather than there -- every link the
     * panel builds is a filter change, and a filter change that kept `shown`
     * would answer with a page nobody had scrolled to.
     */
    private function filterPanel(): SearchFilterPanel
    {
        return $this->filterPanel ??= new SearchFilterPanel(
            $this->data(),
            $this->get,
            $this->connection(),
            $this->presenter(),
            fn(array $query): string => $this->link(array_merge($query, [self::GET_SHOWN => null])),
        );
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
            // A query string parses into nothing but string or nested array
            // -- never an object or a resource -- so this is the real type
            // behind Input::raw()'s deliberately wider `mixed`, here.
            /** @var string|array<array-key, string|array<array-key, mixed>> $value */
            $value = $this->request->query->raw($key);

            // A checkbox group arrives as an array, a shared link as a string.
            // The array is flattened to strings and re-keyed: these go straight
            // into a query string, and a nested value would be printed as the
            // word "Array".
            $carried[$key] = match (true) {
                is_string($value) && $value !== '' => $value,
                is_array($value) && $value !== [] => array_values(array_map(self::stringify(...), $value)),
                default => null,
            };
        }

        return $carried;
    }

    /**
     * A checkbox-group entry, flattened. Only ever string or nested array --
     * a query string parses into nothing else -- so a further-nested array
     * prints as the word "Array", same as `strval()` on one would.
     *
     * @param string|array<array-key, mixed> $value
     */
    private static function stringify(string|array $value): string
    {
        return is_array($value) ? 'Array' : $value;
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

    /**
     * The search response, which exists from the moment the search has run.
     *
     * The property is nullable because it is not set until `index()` has run
     * the search, and every method that reads it is reachable only from there
     * -- after that point. PHP has no way to say "set by the time you get
     * here", so this is the one place that says it, and level 8 stops asking
     * forty-three times (E10.6, #204).
     *
     * A throw rather than a fallback: there is no sensible empty search
     * response, and a method reading this before the search has run is a
     * mistake in the calling order rather than an empty page.
     *
     * @return ResponseSearch
     */
    private function data(): array
    {
        return $this->data ?? throw new RuntimeException('The search has not run yet.');
    }

    /** The search this page is of, for the same reason as data(). */
    private function searchUrl(): SearchUrl
    {
        return $this->searchUrl ?? throw new RuntimeException('The search URL has not been resolved yet.');
    }

    /**
     * @param SearchGetRaw $get
     */
    private function setGet(array $get): void
    {
        // Normalise the trip type, defaulting to one-way for invalid input.
        $get[self::GET_TRIPTYPE] = TripType::fromRequest($get[self::GET_TRIPTYPE])->value;

        $this->get = $get;
    }

    /** @param ResponseSearch $data */
    private function setData(array $data): void
    {
        $this->data = $data;
    }

}
