<?php

declare(strict_types=1);

namespace TripBuilder\View;

use DateTimeImmutable;
use Exception;
use Throwable;
use TripBuilder\ArticleRating;
use TripBuilder\Config;
use TripBuilder\Csrf;
use TripBuilder\Currency;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\Helper;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Repository\ArticleVoteRepository;
use TripBuilder\Repository\BookingTicketRepository;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\CountryRepository;
use TripBuilder\Repository\CurrencyRateRepository;
use TripBuilder\Repository\DashboardRepository;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\Repository\ScheduledJobRepository;
use TripBuilder\Repository\ScheduleRunRepository;
use TripBuilder\RouteAddress;
use TripBuilder\Routes;
use TripBuilder\Schedule;
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

    /** '' once asked and found nothing, so the query runs once either way. */
    private ?string $ratesDate = null;

    /**
     * The active currency's recent rates, once asked for.
     *
     * `false` once asked and found nothing worth drawing, for the same reason
     * `$ratesDate` uses '': null has to mean "not asked yet" or the query runs
     * on every call that finds nothing.
     *
     * @var array{code: string, points: string, from: string, to: string, days: int, change: float}|false|null
     */
    private array|false|null $ratesHistory = null;

    /**
     * How far back the line in the currency panel reaches.
     *
     * Publication days, not calendar days: the source publishes on working days
     * only, so this is about four months of wall clock. Long enough to show a
     * trend and short enough that ninety points across 220 pixels are still
     * more than two pixels apart.
     */
    private const int RATES_HISTORY_DAYS = 90;

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
        // A URL, so it resolves under the document root and not the project.
        $file = Helper::getPublicDir() . '/' . ltrim($path, '/');
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

    /**
     * The two footer figures that cost nothing to print. `queryCount()` is a
     * running total already kept on the one connection every page shares,
     * and `Timer` is already started for every request regardless of who
     * reads it -- neither is a query of its own.
     *
     * Not `stats()`: that one also runs `flightsCount()`, a real `SELECT
     * COUNT(*) FROM flights`, which is the public footer's own furniture
     * and the reason `AdminController` reads none of `stats()` at all
     * (A3.5, #230). The admin footer wants the two free figures without
     * reviving the one that is not (G6.3, #346).
     *
     * @return array{execution_time: string, database_requests: int}
     */
    public function footerPerf(): array
    {
        return [
            'execution_time' => $this->executionTime(),
            'database_requests' => $this->connection()->queryCount(),
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

    /**
     * How many things Overview's own "What needs attention" list would
     * show right now -- the rail's own badge (G17, #375) reads this
     * rather than the list Overview's own `AdminController::index()`
     * already builds, since the rail renders on every admin page, not
     * just that one. The same four checks `DashboardRepository::attention()`
     * already runs; a database that will not answer costs the badge, not
     * the page, the same fallback every other count on this class gives.
     */
    public function adminAttentionCount(): int
    {
        try {
            $health = Schedule::fromRows(new ScheduledJobRepository($this->connection())->allEnabled())
                ->health(new DateTimeImmutable(), new ScheduleRunRepository($this->connection())->all());

            return count(new DashboardRepository($this->connection())->attention($health));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * How many confirmed bookings still have a passenger with no ticket --
     * Bookings' own rail badge (G17's mechanism, second real use, G18,
     * #376), read the same way `adminAttentionCount()` is: a count the
     * rail can ask for on any admin page, not just the one that already
     * builds it for its own reasons.
     */
    public function bookingsMissingTicketCount(): int
    {
        try {
            return new BookingTicketRepository($this->connection())->bookingsNeedingTickets();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * How many enabled jobs are stale or have never run -- the Schedule
     * rail item's own badge, the same reasoning `bookingsMissingTicketCount()`
     * gives: `adminAttentionCount()` already folds this into Overview's
     * total, but the item that is actually about the schedule should be
     * able to say so without a detour through Overview (G19, #377).
     */
    public function scheduleIssueCount(): int
    {
        try {
            $health = Schedule::fromRows(new ScheduledJobRepository($this->connection())->allEnabled())
                ->health(new DateTimeImmutable(), new ScheduleRunRepository($this->connection())->all());

            return count(array_filter($health, static fn(array $task): bool => $task['stale']));
        } catch (Throwable) {
            return 0;
        }
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
        // information_schema.tables.table_rows is a plain INT column and
        // comes back as a native int, live-verified the same way every
        // other plain int column in this codebase has been.
        /** @var int $rows */
        $rows = $this->connection()->fetchValue(
            'SELECT table_rows FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ?',
            [Table::Flights->value],
        );

        return $rows < self::COUNT_ROUND_ABOVE
            ? number_format($rows)
            : '~' . number_format(self::roundToThousand($rows));
    }

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
            $from = $route['from_name'];
            $to = $route['to_name'];

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
            'top-rated-help' => $this->topRatedHelp($limit),
            default => [],
        };
    }

    /**
     * The day the rates being used were published, or null.
     *
     * Lazy and memoised: it is one query for one line in the switcher panel,
     * and a page whose visitor never opens the panel still pays for it, so it
     * is not resolved until a template asks. Null where nothing has been
     * fetched, which the panel says differently -- naming a date we do not have
     * would be the one dishonest thing this feature could do.
     */
    public function ratesDate(): ?string
    {
        if ($this->ratesDate !== null) {
            return $this->ratesDate === '' ? null : $this->ratesDate;
        }

        try {
            $date = new CurrencyRateRepository($this->connection())->latestDate();
        } catch (Throwable) {
            $date = null;
        }

        $this->ratesDate = $date ?? '';

        return $date;
    }

    /**
     * The active currency's recent rates, shaped for the panel's sparkline.
     *
     * Lazy and memoised like `ratesDate()` beside it, and for the same reason:
     * one query for one line that most visitors never open.
     *
     * Null for three cases that are all "there is no line here", and the panel
     * treats them the same. The base currency, which is 1.00 against itself
     * every day and would draw a flat line saying nothing. A table that has not
     * been backfilled, which holds one day and one day is a point. And a
     * database that would not answer.
     *
     * @return array{code: string, points: string, from: string, to: string, days: int, change: float}|null
     */
    public function ratesHistory(): ?array
    {
        if ($this->ratesHistory !== null) {
            return $this->ratesHistory === false ? null : $this->ratesHistory;
        }

        $this->ratesHistory = false;
        $active = Currency::active();

        if ($active->code === Currency::base()->code) {
            return null;
        }

        try {
            $history = new CurrencyRateRepository($this->connection())
                ->history($active->code, self::RATES_HISTORY_DAYS);
        } catch (Throwable) {
            return null;
        }

        $values = array_values($history);
        $points = Sparkline::points($values);

        if ($points === null) {
            return null;
        }

        $first = $values[0];
        $last = $values[count($values) - 1];

        $this->ratesHistory = [
            'code' => $active->code,
            'points' => $points,
            'from' => self::rateLabel($first),
            'to' => self::rateLabel($last),
            'days' => count($values),
            // Against where it started, which is what "over ninety days" means.
            'change' => $first > 0 ? round(($last - $first) / $first * 100, 1) : 0.0,
        ];

        return $this->ratesHistory;
    }

    /**
     * A rate written to a useful number of places.
     *
     * One rule will not do: the same column holds 0.62332 euros and 12,700
     * rupiah to the dollar, and four decimal places on the second is digits of
     * noise while two on the first is not a rate at all.
     *
     * Rounding further than this was tried and taken back out. The caption
     * prints both ends *and* the percentage between them, so a reader can check
     * one against the other -- and at no decimal places the yen read "115 to
     * 111, -3.3%", which is arithmetic anybody can see is wrong.
     */
    private static function rateLabel(float $rate): string
    {
        return $rate >= 1 ? number_format($rate, 2) : number_format($rate, 4);
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
            $name = $airline['name'];
            $links[$name] = Helper::airlineUrl($name, $airline['code']);
        }

        return $links;
    }

    /**
     * The help articles, best-regarded first.
     *
     * The one column here ranked by what readers said rather than by what they
     * searched or booked. Two reads, not one: the articles table gives the full
     * set and the votes table only says how each one has done, so an article
     * nobody has voted on is still in the column, at the bottom, rather than
     * missing from it. That is the opposite of how the other five work, where
     * absence of data means absence from the column.
     *
     * Ordered by ArticleRating::score() and not by the share who said yes,
     * because one reader saying yes would otherwise outrank forty saying so --
     * see that class for the figures.
     *
     * @return array<string, string> label => url
     */
    public function topRatedHelp(int $limit): array
    {
        try {
            $articles = new ArticleRepository($this->connection())->all();
        } catch (Throwable) {
            // The column takes itself out, which is what the other five do
            // when their query fails. This read used to be config and could
            // not fail; now that it can, a broken database costs the column
            // rather than the footer.
            return [];
        }

        $tally = [];

        try {
            $tally = new ArticleVoteRepository($this->connection())->tally();
        } catch (Throwable) {
            // Nothing to rank by, so the order the articles are stored in
            // stands -- which is what a database nobody has voted on gives
            // anyway. A failure here takes the ranking away, not the column.
        }

        $ranked = [];
        $position = 0;

        foreach ($articles as $slug => $article) {
            $ranked[$slug] = [
                // The short name where the article has one: two of the titles
                // are wider than this column.
                'label' => $article['short'] ?? $article['title'],
                'score' => ArticleRating::score(
                    $tally[$slug]['helpful'] ?? 0,
                    $tally[$slug]['votes'] ?? 0,
                ),
                'position' => $position++,
            ];
        }

        // Score down, then the order the table gives them. The tiebreaker is
        // not decoration: with no votes every article scores nought, so on a
        // fresh database `position` decides the whole column -- and every
        // other ranking here carries one for the same reason.
        uasort(
            $ranked,
            static fn(array $a, array $b): int
                => [$b['score'], $a['position']] <=> [$a['score'], $b['position']],
        );

        $links = [];

        foreach (array_slice($ranked, 0, max(1, $limit), true) as $slug => $item) {
            $links[$item['label']] = '/help/' . $slug;
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
            $name = $country['name'];
            $links[$name] = '/country/' . Helper::placeSlug($name, $country['code']);
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
        return self::oneShotNotice('subscribe_notice');
    }

    /**
     * The answer to a vote cast with no scripting, once.
     *
     * Registered as a Twig function and not a global, for the reason the
     * subscribe one is: a global is evaluated on every page, so the first page
     * the visitor happened to load would swallow the notice meant for the
     * article they voted on.
     *
     * @return array{tone: string, message: string}|null
     */
    public function articleVoteNotice(): ?array
    {
        return self::oneShotNotice('article_vote_notice');
    }

    /**
     * Read a session notice and clear it in the same breath.
     *
     * Shared by both callers rather than written twice. The clearing is the
     * part worth having in one place: a notice that is read without being
     * unset goes on announcing itself on every page until the session ends.
     *
     * @return array{tone: string, message: string}|null
     */
    private static function oneShotNotice(string $key): ?array
    {
        $notice = $_SESSION[$key] ?? null;

        unset($_SESSION[$key]);

        if (!is_array($notice) || !isset($notice['tone'], $notice['message'])) {
            return null;
        }

        // $_SESSION is opaque to phpstan regardless of what is written into
        // it -- both writers (AjaxController::answerVote()/answerSubscribe())
        // store exactly this shape.
        /** @var array{tone: string, message: string} $notice */
        return $notice;
    }

    /**
     * To the nearest thousand, so the digits that are shown are ones the
     * estimate can stand behind.
     */
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
