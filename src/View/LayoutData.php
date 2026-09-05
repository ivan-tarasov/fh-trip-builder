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
     * Request-scoped footer stats, computed eagerly so their order is fixed:
     * the flights count runs the only query on this connection, and the request
     * counter is read afterwards so it reflects that query (as before).
     *
     * @return array<string, string|int>
     *
     * @throws Exception
     */
    public function stats(): array
    {
        $flightsCount = $this->flightsCount();

        return [
            'flights_count' => number_format($flightsCount),
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
    private function flightsCount(): int
    {
        return (int) $this->connection()->fetchValue('SELECT count(*) FROM ' . Table::Flights->value);
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
