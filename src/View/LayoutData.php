<?php

declare(strict_types=1);

namespace TripBuilder\View;

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
     * Request-scoped footer stats, computed eagerly so their order is fixed:
     * the flights count runs the only query on this connection, and the request
     * counter is read afterwards so it reflects that query (as before).
     *
     * @return array<string, string|int>
     */
    public function stats(): array
    {
        $flightsCount = $this->flightsCount();

        return [
            'flights_count'     => number_format($flightsCount),
            'database_requests' => $this->connection()->queryCount(),
            'execution_time'    => $this->executionTime(),
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
     * @throws \Exception
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
        $appYear     = (string) Config::get('app.year');
        $currentYear = date('Y');

        return $appYear === $currentYear
            ? $currentYear
            : $appYear . '–' . $currentYear;
    }

    /**
     * @throws \Exception
     */
    private function flightsCount(): int
    {
        return (int) $this->connection()->fetchValue('SELECT count(*) FROM ' . Table::Flights->value);
    }

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
