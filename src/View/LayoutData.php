<?php

declare(strict_types=1);

namespace TripBuilder\View;

use Exception;
use TripBuilder\Config;
use TripBuilder\Csrf;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\Helper;
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
