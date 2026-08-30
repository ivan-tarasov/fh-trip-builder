<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use DateInterval;
use DateTime;
use Exception;
use stdClass;
use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\SearchRepository;
use TripBuilder\Service\FlightFinder;
use TripBuilder\TripType;
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
        GET_RETURN_ITIN = 'return_itin';

    // What counts as worth warning about on an itinerary.
    private const int LAYOVER_TIGHT_MINUTES = 90;
    private const int LAYOVER_LONG_MINUTES = 300;
    private const int LONG_TRIP_MINUTES = 1440;
    private const int NIGHT_FROM_HOUR = 23;
    private const int NIGHT_TO_HOUR = 6;

    private const string POST_SORT = 'sort',
        POST_TIME_RANGE = 'time_range',
        POST_AIRLINES = 'airlines';

    private array $get;

    private ?array $post;

    private ?stdClass $data = null;

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
            ]);

            // Convert search hash to url and redirect
            $this->checkHash();

            // Handle POST data
            $this->setPost($_POST
                ? [
                    self::POST_SORT => $_POST[self::POST_SORT] ?? false,
                    self::POST_TIME_RANGE => $_POST[self::POST_TIME_RANGE] ?? false,
                    self::POST_AIRLINES => $_POST[self::POST_AIRLINES] ?? false,
                ] : null);

            // Handle SESSION data
            if ($this->post && $this->post[self::POST_SORT]) {
                $_SESSION[self::POST_SORT] = $this->post[self::POST_SORT];
            } elseif (!isset($_SESSION[self::POST_SORT]) || !$this->get[self::GET_PAGE]) {
                $_SESSION[self::POST_SORT] = 'price';
            }

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
                sort: $_SESSION[self::POST_SORT],
                from: $this->get[self::GET_FROM],
                to: $this->get[self::GET_TO],
                departDate: $this->get[self::GET_DEPART],
                returnDate: $this->get[self::GET_RETURN] ?? '',
                adultNum: 1, // FIXME: now we provide only 1 adult count
                childNum: 0, // FIXME: now we provide only 0 child count
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
                'session_sort' => $_SESSION[self::POST_SORT],
                'clock_range' => $this->generateTimeRange(),
                'airlines' => $this->fetchSidebarAirlines(),
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
                    : $this->buildDirection($this->data->selected)['direction'],
                'selected_price' => $this->data->selected_price === null
                    ? null
                    : number_format((float) $this->data->selected_price, 2),
                'selected_return' => $this->data->selected_return === null
                    ? null
                    : $this->buildDirection($this->data->selected_return)['direction'],
                'selected_return_price' => $this->data->selected_return_price === null
                    ? null
                    : number_format((float) $this->data->selected_return_price, 2),
                'package_price' => $this->data->package_price === null
                    ? null
                    : number_format((float) $this->data->package_price, 2),
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
        // Prevent too many counts from one user
        if ($this->post || $this->get[self::GET_PAGE] != 1) {
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

        foreach ($this->data->flights as $flight) {
            $built = $this->buildDirection($flight->itinerary);

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
                'price_total' => number_format((float) $flight->price_base + (float) $flight->price_tax, 2),
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
     * Build one direction's view-model — both the collapsed compact line
     * (leading carrier, first/last times with a next-day offset, total duration,
     * a layover entry per stop) and the expanded per-segment detail shown under
     * "flight details". Also returns the segment ids.
     *
     * @return array{direction: array<string, mixed>, ids: list<int>}
     * @throws Exception
     */
    private function buildDirection(object $itinerary): array
    {
        $segments = $itinerary->segments;
        $first = $segments[0];
        $last = $segments[array_key_last($segments)];

        $ids = [];
        $detail = [];

        foreach ($segments as $segment) {
            $ids[] = (int) $segment->id;

            $detail[] = [
                'carrier_name' => $segment->carrier_name,
                'logo_url' => $this->carrierLogo($segment->carrier),
                'flight_number' => 'Flight ' . str_replace('-', '', $segment->number),
                'duration' => $this->minutesToStringTime($segment->duration),
                'cabin' => 'Economy',
                'depart_time' => date('H:i', strtotime($segment->depart->date_time)),
                'depart_date' => date('D, d M', strtotime($segment->depart->date_time)),
                'depart_city' => $segment->depart->airport_city,
                'depart_code' => $segment->depart->airport_code,
                'arrive_time' => date('H:i', strtotime($segment->arrive->date_time)),
                'arrive_date' => date('D, d M', strtotime($segment->arrive->date_time)),
                'arrive_city' => $segment->arrive->airport_city,
                'arrive_code' => $segment->arrive->airport_code,
            ];
        }

        // The route bar: one part per flight leg and one per layover, each
        // weighted by its own minutes so the drawn widths show the real shape of
        // the journey. Airport codes sit under the part they belong to.
        $parts = [];
        $lastSegment = array_key_last($segments);

        foreach ($segments as $i => $segment) {
            $parts[] = [
                'type' => 'leg',
                'weight' => max(1, (int) $segment->duration),
                'tooltip' => sprintf(
                    '%s in the air · %s–%s',
                    $this->minutesToStringTime((int) $segment->duration),
                    $segment->depart->airport_code,
                    $segment->arrive->airport_code,
                ),
                'start_code' => $i === 0 ? $segment->depart->airport_code : null,
                'end_code' => $i === $lastSegment ? $segment->arrive->airport_code : null,
                'code' => null,
            ];

            if (isset($itinerary->layovers[$i])) {
                $layover = $itinerary->layovers[$i];

                $parts[] = [
                    'type' => 'stop',
                    'weight' => max(1, (int) $layover->wait_minutes),
                    'tooltip' => sprintf(
                        'Layover at %s (%s) — %s',
                        $layover->airport_name,
                        $layover->airport_city,
                        $this->minutesToStringTime((int) $layover->wait_minutes),
                    ),
                    'start_code' => null,
                    'end_code' => null,
                    'code' => $layover->airport_code,
                ];
            }
        }

        $layovers = [];

        foreach ($itinerary->layovers as $layover) {
            $layovers[] = [
                'airport_code' => $layover->airport_code,
                'airport_city' => $layover->airport_city,
                'wait' => $this->minutesToStringTime($layover->wait_minutes),
            ];
        }

        return [
            'direction' => [
                'stops_label' => $this->stopsLabel((int) $itinerary->stops),
                'duration' => $this->minutesToStringTime((int) $itinerary->total_duration),
                'carrier_name' => $first->carrier_name,
                'logo_url' => $this->carrierLogo($first->carrier),
                'depart_time' => date('H:i', strtotime($first->depart->date_time)),
                'depart_code' => $first->depart->airport_code,
                'depart_city' => $first->depart->airport_city,
                'depart_day' => date('D, j M', strtotime($first->depart->date_time)),
                'arrive_time' => date('H:i', strtotime($last->arrive->date_time)),
                'arrive_code' => $last->arrive->airport_code,
                'arrive_city' => $last->arrive->airport_city,
                'arrive_day' => date('D, j M', strtotime($last->arrive->date_time)),
                'notices' => $this->buildNotices($segments, $itinerary->layovers, (int) $itinerary->total_duration),
                'badges' => array_map($this->badgeMeta(...), $itinerary->badges),
                'route' => $parts,
                'layovers' => $layovers,
                'segments' => $detail,
            ],
            'ids' => $ids,
        ];
    }

    private function carrierLogo(string $carrier): string
    {
        return Cdn::getUrl(sprintf(
            '%s/suppliers/%s.png',
            Config::get('site.static.endpoint.images'),
            $carrier,
        ));
    }


    /**
     * Things about an itinerary a traveller would want to spot before choosing:
     * connections that are tight or long, layovers spent overnight, a transit
     * country that may need a visa, separately-ticketed airlines, and very long
     * journeys. Each notice is deduplicated by label so a two-stop trip doesn't
     * repeat the same warning.
     *
     * @param list<object> $segments
     * @param list<object> $layovers
     * @return list<array<string, string>>
     */
    private function buildNotices(array $segments, array $layovers, int $totalDuration): array
    {
        $notices = [];
        $originCountry = $segments[0]->depart->airport_country;
        $destinationCountry = $segments[array_key_last($segments)]->arrive->airport_country;

        foreach ($layovers as $i => $layover) {
            $wait = (int) $layover->wait_minutes;
            $city = $layover->airport_city;
            $waitLabel = $this->minutesToStringTime($wait);

            if ($wait < self::LAYOVER_TIGHT_MINUTES) {
                $notices['tight'] = [
                    'icon' => 'person-running',
                    'tone' => 'danger',
                    'label' => 'Tight connection',
                    'text' => sprintf('Only %s to change planes in %s', $waitLabel, $city),
                ];
            } elseif ($wait > self::LAYOVER_LONG_MINUTES) {
                $notices['long'] = [
                    'icon' => 'hourglass-half',
                    'tone' => 'warning',
                    'label' => 'Long layover',
                    'text' => sprintf('%s waiting in %s', $waitLabel, $city),
                ];
            }

            if (isset($segments[$i], $segments[$i + 1]) && $this->spansNight(
                $segments[$i]->arrive->date_time,
                $segments[$i + 1]->depart->date_time,
            )) {
                $notices['night'] = [
                    'icon' => 'moon',
                    'tone' => 'warning',
                    'label' => 'Night layover',
                    'text' => sprintf('The wait in %s runs through the night', $city),
                ];
            }

            $country = $segments[$i]->arrive->airport_country;

            if ($country !== $originCountry && $country !== $destinationCountry) {
                $notices['visa'] = [
                    'icon' => 'passport',
                    'tone' => 'info',
                    'label' => 'Transit visa',
                    'text' => sprintf('Connects through %s — check whether a transit visa is needed', $country),
                ];
            }
        }

        $carriers = array_unique(array_map(static fn(object $s): string => $s->carrier, $segments));

        if (count($carriers) > 1) {
            $notices['airlines'] = [
                'icon' => 'suitcase-rolling',
                'tone' => 'danger',
                'label' => 'Separate airlines',
                'text' => 'Flights are on different airlines — bags may need collecting and re-checking',
            ];
        }

        if ($totalDuration > self::LONG_TRIP_MINUTES) {
            $notices['duration'] = [
                'icon' => 'clock',
                'tone' => 'warning',
                'label' => 'Long journey',
                'text' => sprintf('%s door to door', $this->minutesToStringTime($totalDuration)),
            ];
        }

        return array_values($notices);
    }

    /**
     * Whether a window overlaps the small hours on any night it touches.
     */
    private function spansNight(string $from, string $to): bool
    {
        $start = strtotime($from);
        $end = strtotime($to);

        for ($day = strtotime('midnight', $start) - 86400; $day <= $end; $day += 86400) {
            $nightStart = $day + self::NIGHT_FROM_HOUR * 3600;
            $nightEnd = $day + 86400 + self::NIGHT_TO_HOUR * 3600;

            if ($start < $nightEnd && $end > $nightStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Presentation for a badge slug decided by the repository.
     *
     * @return array<string, string>
     */
    private function badgeMeta(string $slug): array
    {
        return match ($slug) {
            'cheapest' => ['label' => 'Cheapest', 'tone' => 'success', 'icon' => 'tag',
                'text' => 'Lowest price of every option we found'],
            'fastest' => ['label' => 'Fastest', 'tone' => 'purple', 'icon' => 'bolt',
                'text' => 'Shortest door-to-door time of every option we found'],
            'value' => ['label' => 'Best value', 'tone' => 'primary', 'icon' => 'thumbs-up',
                'text' => 'The best balance of price and travel time'],
            'nonstop' => ['label' => 'Cheapest nonstop', 'tone' => 'teal', 'icon' => 'plane',
                'text' => 'Lowest price among the flights with no connection'],
            default => ['label' => ucfirst($slug), 'tone' => 'secondary', 'icon' => 'star', 'text' => ''],
        };
    }

    private function stopsLabel(int $stops): string
    {
        return match (true) {
            $stops === 0 => 'Direct',
            $stops === 1 => '1 stop',
            default => $stops . ' stops',
        };
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

    public function minutesToStringTime(int $minutes): string
    {
        $seconds = $minutes * 60;

        $dtF = new DateTime('@0');
        $dtT = new DateTime("@$seconds");

        $interval = $dtF->diff($dtT);

        $timeComponents = [
            'd' => $interval->format('%a'),
            'h' => $interval->format('%h'),
            'm' => $interval->format('%i'),
        ];

        $formattedTime = '';

        foreach ($timeComponents as $unit => $value) {
            if ($value != 0) {
                $formattedTime .= $value . $unit . ' ';
            }
        }

        return trim($formattedTime);
    }

    private function setGet(array $get): void
    {
        // Normalise the trip type, defaulting to one-way for invalid input.
        $get[self::GET_TRIPTYPE] = TripType::fromRequest($get[self::GET_TRIPTYPE])->value;

        $this->get = $get;
    }

    private function setPost(?array $post): void
    {
        $this->post = $post;
    }

    private function setData(stdClass $data): void
    {
        $this->data = $data;
    }

}
