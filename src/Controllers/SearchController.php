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
        GET_PAGE = 'page';

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
                // Flights / no-result
                'total_flights' => $total_flights,
                'total_flights_text' => Helper::plural((int) $total_flights, 'flight', showNumber: true),
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
            foreach ([$item->outbound, $item->returning] as $itinerary) {
                if (empty($itinerary) || empty($itinerary->segments)) {
                    continue;
                }

                foreach ($itinerary->segments as $segment) {
                    $carriers[] = $segment->carrier;
                }
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

        foreach ($this->data->flights as $flight) {
            $outbound = $this->buildDirection($flight->outbound, 'Outbound');
            $directions = [$outbound['direction']];
            $outboundIds = $outbound['ids'];
            $returnIds = [];

            if (!empty($flight->returning) && !empty($flight->returning->segments)) {
                $return = $this->buildDirection($flight->returning, 'Return');
                $directions[] = $return['direction'];
                $returnIds = $return['ids'];
            }

            $flights[] = [
                'outbound_ids' => $outboundIds,
                'return_ids' => $returnIds,
                'price_total' => number_format((float) $flight->price_base + (float) $flight->price_tax, 2),
                'price_base' => number_format((float) $flight->price_base, 2),
                'price_tax' => number_format((float) $flight->price_tax, 2),
                'price_gst' => number_format(0, 2),
                'price_qst' => number_format(0, 2),
                'directions' => $directions,
            ];
        }

        return $flights;
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
    private function buildDirection(object $itinerary, string $label): array
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
                'depart_time' => date('g:ia', strtotime($segment->depart->date_time)),
                'depart_date' => date('D, d M', strtotime($segment->depart->date_time)),
                'depart_city' => $segment->depart->airport_city,
                'depart_code' => $segment->depart->airport_code,
                'arrive_time' => date('g:ia', strtotime($segment->arrive->date_time)),
                'arrive_date' => date('D, d M', strtotime($segment->arrive->date_time)),
                'arrive_city' => $segment->arrive->airport_city,
                'arrive_code' => $segment->arrive->airport_code,
            ];
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
                'label' => $label,
                'stops_label' => $this->stopsLabel((int) $itinerary->stops),
                'duration' => $this->minutesToStringTime((int) $itinerary->total_duration),
                'carrier_name' => $first->carrier_name,
                'logo_url' => $this->carrierLogo($first->carrier),
                'depart_time' => date('g:iA', strtotime($first->depart->date_time)),
                'depart_code' => $first->depart->airport_code,
                'depart_city' => $first->depart->airport_city,
                'arrive_time' => date('g:iA', strtotime($last->arrive->date_time)),
                'arrive_code' => $last->arrive->airport_code,
                'arrive_city' => $last->arrive->airport_city,
                'day_offset' => $this->dayOffset($first->depart->date_time, $last->arrive->date_time),
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

    private function stopsLabel(int $stops): string
    {
        return match (true) {
            $stops === 0 => 'Direct',
            $stops === 1 => '1 stop',
            default => $stops . ' stops',
        };
    }

    /**
     * Whole days between a departure and an arrival (0 = same calendar day,
     * 1 = arrives the next day, …) for the "+N" arrival marker.
     */
    private function dayOffset(string $departDateTime, string $arriveDateTime): int
    {
        $depart = new DateTime(date('Y-m-d', strtotime($departDateTime)));
        $arrive = new DateTime(date('Y-m-d', strtotime($arriveDateTime)));

        return (int) $depart->diff($arrive)->format('%r%a');
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
