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
use TripBuilder\Repository\CityRepository;
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
    /** Paths whose pages are transient, personal, or both. */
    private const array PRIVATE_PREFIXES = ['/search', '/checkout', '/my'];

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

        $path = $this->currentPage();

        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        return true;
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
