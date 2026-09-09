<?php

declare(strict_types=1);

namespace TripBuilder\View;

use Exception;
use Throwable;
use TripBuilder\Config;
use TripBuilder\Csrf;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\Helper;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\CountryRepository;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\RouteAddress;
use TripBuilder\Routes;
use TripBuilder\Timer;

/**
 * Supplies the dynamic header/footer data the base layout needs.
 *
 * The menus themselves come straight from config() inside the templates; this
 * class covers the bits that used to live on AbstractController::header()/
 * footer() — the request-scoped stats, git info, CSRF token and current page.
 */
final class LayoutData
{
    private ?Connection $connection = null;

    /** Below this, the estimate is close enough to print as it comes. */
    private const int COUNT_ROUND_ABOVE = 10000;

    /**
     * A stylesheet or script URL with a version stamp taken from the file's
     * own modification time.
     *
     * Without one, a browser holding a cached copy keeps running the old asset
     * after a deploy — the markup and the code it needs then disagree, which
     * shows up as controls that quietly do nothing.
     */
    public function asset(string $path): string
    {
        $file = Helper::getRootDir() . '/' . ltrim($path, '/');
        $stamp = is_file($file) ? filemtime($file) : false;

        return $stamp === false ? $path : $path . '?v=' . $stamp;
    }

    /**
     * Request-scoped footer stats, computed eagerly so their order is fixed.
     *
     * @return array<string, string|int>
     *
     * @throws Exception
     */
    public function stats(): array
    {
        // Order matters: the flights count runs first so the request counter
        // read below includes it. Both now sit on the one connection the whole
        // request shares, so the count is every query the page ran -- it used
        // to be this class's own connection, and therefore always 1.
        $flightsCount = $this->flightsCount();

        return [
            'flights_count' => $flightsCount,
            'database_requests' => $this->connection()->queryCount(),
            'execution_time' => $this->executionTime(),
        ];
    }

    public function currentPage(): string
    {
        return Routes::getCurrentPage();
    }

    /**
     * The breadcrumb trail for the page being rendered.
     *
     * Empty for pages that show none -- home, and the booking funnel, which
     * carries a step indicator instead.
     *
     * @return list<array{label: string, url: string|null, current: bool}>
     */
    public function breadcrumbs(): array
    {
        return Breadcrumbs::trail($this->currentPage());
    }

    /**
     * Whether a nav link points at the section the current page sits in.
     */
    public function inSection(string $path): bool
    {
        return Breadcrumbs::covers($path, $this->currentPage());
    }

    public function csrfToken(): string
    {
        return Csrf::token();
    }

    /**
     * What to call the hidden input that carries the token.
     *
     * Beside the token itself so a form can name both from one place. The two
     * that post one had written the name out, and had written two different
     * ones -- see the note in AjaxController::guardFailure().
     */
    public function csrfField(): string
    {
        return Csrf::FIELD;
    }

    /**
     * @return array<string, string>
     * @throws Exception
     */
    public function gitInfo(): array
    {
        return Helper::getGitInfo();
    }

    public function gitRepo(): string
    {
        return Helper::getGitRepo();
    }

    public function copyrightYears(): string
    {
        $appYear = (string) Config::get('app.year');
        $currentYear = date('Y');

        return $appYear === $currentYear
            ? $currentYear
            : $appYear . '–' . $currentYear;
    }

    /**
     * @throws Exception
     */
    private function flightsCount(): string
    {
        // InnoDB does not keep a row count, so COUNT(*) scans an index -- 45ms
        // of every page render, for a line in the footer that sits next to the
        // execution time. The optimiser's own estimate costs 2ms.
        //
        // It is an estimate, and after a large delete it can be several percent
        // stale, so it is rounded and marked rather than printed as though it
        // were counted. A precise-looking wrong number is worse than an
        // obviously approximate right one.
        $rows = (int) $this->connection()->fetchValue(
            'SELECT table_rows FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ?',
            [Table::Flights->value],
        );

        return $rows < self::COUNT_ROUND_ABOVE
            ? number_format($rows)
            : '~' . number_format(self::roundToThousand($rows));
    }

    /**
     * To the nearest thousand, so the digits that are shown are ones the
     * estimate can stand behind.
     */
    /**
     * The one address this page answers at.
     *
     * Every page here is reachable at more than one URL. A trailing slash is
     * optional -- Request::path() rtrims it and both forms return 200 -- and
     * any query string at all makes another: /airlines?utm_source=x is a fourth
     * copy of a page that has one piece of content. Without a canonical each of
     * those competes with the others.
     *
     * The path the router normalised to, which is the form it treats as the
     * page's identity, and no query. Relative rather than absolute for the same
     * reason the breadcrumb JSON-LD is: this app knows no canonical host, and
     * inventing one would be a second source of truth nothing could keep right.
     *
     * A search or a checkout has no business having one of these -- see
     * indexable() -- but it costs nothing to answer honestly for them too.
     */
    public function canonicalPath(): string
    {
        return $this->currentPage();
    }

    /**
     * Whether a search engine should keep this page.
     *
     * Three kinds of page should not be kept. A search result is a snapshot of
     * prices that will be wrong tomorrow, and there are more possible search
     * URLs than there are flights. A checkout is a step in a transaction. And
     * /my is one browser's own bookings -- nothing there is public, and a
     * session that has ended renders it empty.
     *
     * A page answering 404 is the fourth: the router has already said it is not
     * a page, and this stops a crawler holding on to the URL that led there.
     */
    public function indexable(): bool
    {
        if (http_response_code() === 404) {
            return false;
        }

        // Routes::isPublic() and not a second list here: the sitemap asks the
        // same question, and two copies of the answer would drift.
        return Routes::isPublic($this->currentPage());
    }

    /**
     * The most-searched cities, ready for the footer's link column.
     *
     * Returned as `name => url` because that is the shape the column partial
     * draws, and slugged here because the URL spelling is this app's business
     * rather than the database's.
     *
     * A failure here is not worth a broken page. The footer already depends on
     * the database for its flight count, so this is not a new risk -- but that
     * one has a fallback and so does this: an empty list, and the column takes
     * itself out.
     *
     * @return array<string, string>
     */
    public function mostSearchedCities(int $limit): array
    {
        try {
            $cities = new CityRepository($this->connection())->mostSearched($limit);
        } catch (Throwable) {
            return [];
        }

        $links = [];

        foreach ($cities as $city) {
            $name = (string) $city['name'];
            $links[$name] = '/city/' . Helper::placeSlug($name, (string) $city['code']);
        }

        return $links;
    }

    /**
     * The most-searched routes, ready for the footer's link column.
     *
     * The label is both city names, which is what the page is called and what
     * somebody scanning a footer is looking for. Same shape and same fallback
     * as the cities above it.
     *
     * Every one of these resolves. RouteRepository::popular() only returns
     * pairs that can be flown nonstop, because a search is recorded for any
     * pair anybody asked about and only some of those have a page -- see the
     * comment there.
     *
     * @return array<string, string>
     */
    public function popularRoutes(int $limit): array
    {
        try {
            $routes = new RouteRepository($this->connection())->popular($limit);
        } catch (Throwable) {
            return [];
        }

        $links = [];

        foreach ($routes as $route) {
            $from = (string) $route['from_name'];
            $to = (string) $route['to_name'];

            // Held together by no-break spaces inside each name, so the only
            // place the label may wrap is the dash between them. "Fort
            // Lauderdale — San Francisco" does not fit a 190px column and broke
            // inside "San Francisco", which reads as two entries; broken at the
            // dash it reads as the one it is. Characters and not markup,
            // because this is a label in a map the template escapes.
            $links[self::unbroken($from) . ' — ' . self::unbroken($to)] = RouteAddress::path($from, $to);
        }

        return $links;
    }

    /**
     * A name with no space a line may break at.
     *
     * U+00A0 for every space in it. Only the two spaces around the separator
     * are left breakable, which is where a pair of city names should come apart
     * if it has to.
     */
    private static function unbroken(string $name): string
    {
        return str_replace(' ', "\u{00A0}", $name);
    }

    /**
     * Whatever a data-driven footer column asked for.
     *
     * The template used to call mostSearchedCities() directly, which worked
     * while one column was counted. Two are, so the template asks by name and
     * this decides -- otherwise the choice becomes a conditional in a
     * template, and a third column becomes a longer one.
     *
     * An unknown source is an empty list rather than an error: the column then
     * takes itself out, which is what a column with nothing to show should do
     * whatever the reason.
     *
     * @return array<string, string>
     */
    public function footerLinks(string $source, int $limit): array
    {
        return match ($source) {
            'most-searched' => $this->mostSearchedCities($limit),
            'popular-routes' => $this->popularRoutes($limit),
            'most-booked-airlines' => $this->mostBookedAirlines($limit),
            'most-searched-countries' => $this->mostSearchedCountries($limit),
            'most-searched-airports' => $this->mostSearchedAirports($limit),
            default => [],
        };
    }

    /**
     * A footer "All ..." link with its count filled in.
     *
     * The number has to be the one the page behind the link actually lists, so
     * each count comes from the repository that draws that page and reuses the
     * same filter. A COUNT written a second time here would agree today and
     * part company the first time one of those filters changes.
     *
     * @param array<string, string> $more
     *
     * @return array<string, string>
     */
    public function footerMore(array $more): array
    {
        $total = isset($more['total']) ? $this->directoryTotal($more['total']) : null;

        // No count: drop the placeholder rather than the link. "All airlines"
        // still leads where it led before this had a number in it.
        $more['text'] = $total === null
            ? str_replace('%s ', '', $more['text'])
            : sprintf($more['text'], number_format($total));

        return $more;
    }

    /** How many rows one of the directory pages lists, or null if it will not say. */
    private function directoryTotal(string $key): ?int
    {
        try {
            return match ($key) {
                'cities' => new CityRepository($this->connection())->countAll(),
                'countries' => new CountryRepository($this->connection())->countSellable(),
                'airports' => new AirportRepository($this->connection())->countEnabled(true),
                'airlines' => new AirlineRepository($this->connection())->countSellable(),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The airlines people book, ready for the footer's link column.
     *
     * Counted rather than curated, like the two columns above it. Same shape
     * and the same fallback: a database that will not answer costs the column,
     * not the page.
     *
     * @return array<string, string>
     */
    public function mostBookedAirlines(int $limit): array
    {
        try {
            $airlines = new AirlineRepository($this->connection())->mostBooked($limit);
        } catch (Throwable) {
            return [];
        }

        $links = [];

        foreach ($airlines as $airline) {
            $name = (string) $airline['name'];
            $links[$name] = Helper::airlineUrl($name, (string) $airline['code']);
        }

        return $links;
    }

    /**
     * The countries whose airports are searched for most.
     *
     * @return array<string, string>
     */
    public function mostSearchedCountries(int $limit): array
    {
        try {
            $countries = new CountryRepository($this->connection())->mostSearched($limit);
        } catch (Throwable) {
            return [];
        }

        $links = [];

        foreach ($countries as $country) {
            $name = (string) $country['name'];
            $links[$name] = '/country/' . Helper::placeSlug($name, (string) $country['code']);
        }

        return $links;
    }

    /**
     * The busiest airport of each of the most-searched cities.
     *
     * Labelled "London (LHR)" rather than "Heathrow", which is what the page is
     * called. Two reasons, and the second is the one that decided it: half
     * these titles do not say where they are -- "Pierre Elliott Trudeau
     * International" names a man, not Montreal -- and the ones that do say it
     * at length, which in a column 190px wide is two and three lines apiece.
     * The city and the code are what a traveller reads an airport by anyway,
     * and they fit on one line every time.
     *
     * @return array<string, string>
     */
    public function mostSearchedAirports(int $limit): array
    {
        try {
            $airports = new AirportRepository($this->connection())->mostSearched($limit);
        } catch (Throwable) {
            return [];
        }

        $links = [];

        foreach ($airports as $airport) {
            $code = (string) $airport['code'];
            $links[$airport['city'] . ' (' . $code . ')'] = Helper::airportUrl(
                (string) $airport['title'],
                $code,
            );
        }

        return $links;
    }

    /**
     * The answer to a form post, once, from whoever left it in the session.
     *
     * Read and cleared in the same breath. A notice that stayed would be shown
     * again on the next page and on every refresh, which is how a sign-up from
     * ten minutes ago ends up announcing itself over somebody's search results.
     *
     * Only a browser that posted the form without scripting ever sees this --
     * with the script running, the answer never leaves the page it was asked
     * on. See AjaxController::answerSubscribe().
     *
     * @return array{tone: string, message: string}|null
     */
    public function subscribeNotice(): ?array
    {
        $notice = $_SESSION['subscribe_notice'] ?? null;

        unset($_SESSION['subscribe_notice']);

        if (!is_array($notice) || !isset($notice['tone'], $notice['message'])) {
            return null;
        }

        return ['tone' => (string) $notice['tone'], 'message' => (string) $notice['message']];
    }

    private static function roundToThousand(int $rows): int
    {
        return (int) round($rows, -3);
    }

    /**
     * @throws Exception
     */
    private function executionTime(): string
    {
        Timer::stop();

        return Timer::getExecutionTime();
    }

    private function connection(): Connection
    {
        return $this->connection ??= Connection::fromEnv();
    }
}
