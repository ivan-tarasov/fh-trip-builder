<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use DateInterval;
use DateTime;
use Exception;
use stdClass;
use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\AirlineRepository;
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
                    http_build_query(array_merge($this->get, [self::GET_PAGE => null])),
                ),
                // Filter forms submit with GET, so they post to the bare path
                // and carry the rest of the search as hidden fields.
                'form_path' => Helper::getUrlPath(),
                'session_sort' => $this->sort(),
                'clock_range' => $this->generateTimeRange(),
                'airlines' => $this->fetchSidebarAirlines(),
                // What the sidebar needs to draw itself: the filters currently
                // applied, and which options are worth offering at all.
                'filters' => $this->filterQuery(),
                'available' => (array) $this->data->available,
                // Hidden fields a GET form needs so submitting one control does
                // not drop the rest of the search.
                'carried_query' => array_filter(
                    $this->get,
                    static fn(mixed $v, string $k): bool => $v !== null && $v !== ''
                        && !in_array($k, [self::GET_SORT, self::GET_PAGE, self::GET_HASH], true),
                    ARRAY_FILTER_USE_BOTH,
                ),
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
     * Fetch the airlines present in the flights response for the sidebar filter.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchSidebarAirlines(): array
    {
        $carriers = [];

        foreach ($this->data->flights ?? [] as $item) {
            foreach ($item->itinerary->segments as $segment) {
                $carriers[] = $segment->carrier;
            }
        }

        $carriers = array_values(array_unique($carriers));

        if ($carriers === []) {
            return [];
        }

        return new AirlineRepository($this->connection())->search($carriers, false);
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
            http_build_query(array_merge($this->get, [
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
            http_build_query(array_merge($this->get, [
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
            http_build_query(array_merge($this->get, [self::GET_PAGE => $page])),
        );
    }

    /**
     * Generating string for Time Range javascript
     */
    private function generateTimeRange(): string
    {
        $startTime = new DateTime('00:00');
        $endTime = new DateTime('23:59');
        $interval = new DateInterval('PT30M');

        $range = [];

        for ($time = clone $startTime; $time <= $endTime; $time->add($interval)) {
            $range[] = $time->format('H:i');
        }

        $range[] = '23:59';

        return "'" . implode("','", $range) . "'";
    }

    /**
     * The filter query keys as they arrived, untouched.
     *
     * They are kept verbatim rather than re-serialised from the parsed filters
     * so that every URL the page builds reproduces exactly the search that
     * produced it — including a value the parser rejected, which stays visible
     * in the address bar instead of silently vanishing.
     *
     * @return array<string, string|null>
     */
    private function filterQuery(): array
    {
        $carried = [];

        foreach (FlightFilters::QUERY_KEYS as $key) {
            $value = $_GET[$key] ?? null;
            $carried[$key] = is_string($value) && $value !== '' ? $value : null;
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
