<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Alerts;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Mail\Mailtrap;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\RoutePriceRepository;
use TripBuilder\Repository\RouteWatchRepository;

#[AsCommand(
    name: self::NAME,
    description: 'Check watched routes against their price threshold and email the ones that qualify.',
    aliases: [],
    hidden: false,
)]

/**
 * A route's cheapest upcoming day against every watch on it (C6, #155).
 *
 * One `RoutePriceRepository::cheapest()` read per distinct route rather than
 * per watch, since several people can watch the same pair.
 *
 * A watch is emailed once per new low: `notified_price` starts null, is set
 * to the price a mail was just sent for, and is cleared the moment the price
 * rises back above the threshold. So a route sitting still under threshold
 * sends exactly one mail rather than one every tick, a further drop sends
 * again, and a price that recovers and dips again is treated as new.
 *
 * A failed send does not fail the run, the same reasoning `Schedule\Run`
 * gives for a scheduled command that threw: one bad address is that watch's
 * problem, not a reason the rest of the list hears nothing.
 */
final class Check extends AbstractCommand
{
    public const string NAME = 'alerts:check';

    /** No `configure()` -- this command takes nothing. */
    public const array ARGUMENTS = [];
    public const array OPTIONS = [];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $watches = new RouteWatchRepository($this->connection());
        $routes = $watches->routes();

        // Mailtrap's credentials are only asked for once there is something
        // that might need them. A server with nothing watched yet, or CI's
        // own empty database, should not fail this for want of a token it
        // has no use for.
        if ($routes === []) {
            $this->formatOutput('Routes checked', '0', 'info');
            $this->formatOutput('Alerts sent', '0', 'default');

            return Command::SUCCESS;
        }

        try {
            $mailer = Mailtrap::fromEnvironment();
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        $prices = new RoutePriceRepository($this->connection());
        $airports = new AirportRepository($this->connection());
        $today = date('Y-m-d');

        $checked = 0;
        $sent = 0;

        foreach ($routes as $route) {
            $cabin = CabinClass::from($route['cabin']);
            $cheapest = $prices->cheapest($route['from_code'], $route['to_code'], $cabin, $today);
            $checked++;

            if ($cheapest === null) {
                continue;
            }

            $total = $cheapest['base'] + $cheapest['tax'];

            foreach ($watches->forRoute($route['from_code'], $route['to_code'], $cabin) as $watch) {
                if ($total > $watch['threshold']) {
                    if ($watch['notified_price'] !== null) {
                        $watches->clearNotified($watch['id']);
                    }

                    continue;
                }

                if (!self::qualifies($total, $watch['notified_price'])) {
                    continue;
                }

                try {
                    $mailer->send(
                        $watch['email'],
                        sprintf('Price drop: %s to %s', $route['from_code'], $route['to_code']),
                        self::body($airports, $route['from_code'], $route['to_code'], $total, $cheapest['depart_date']),
                    );
                    $watches->markNotified($watch['id'], $total);
                    $watches->recordAlertSent();
                    $sent++;
                } catch (Throwable $e) {
                    $this->io->error(sprintf('Could not email %s: %s', $watch['email'], $e->getMessage()));
                }
            }
        }

        $this->formatOutput('Routes checked', (string) $checked, 'info');
        $this->formatOutput('Alerts sent', (string) $sent, $sent > 0 ? 'success' : 'default');

        return Command::SUCCESS;
    }

    /**
     * Whether a watch already known to be at or under its threshold should
     * actually be emailed -- pure, and the one part of this decision worth
     * testing without a database or a network call, the same reasoning
     * `Rates::parse()` gives for splitting itself from `fetch()`.
     *
     * True the first time a watch is seen under threshold, and any further
     * time the price is a new low. Once notified at a price, the same price
     * or a higher one -- still under threshold -- says nothing new.
     */
    public static function qualifies(float $total, ?float $notifiedPrice): bool
    {
        return $notifiedPrice === null || $total < $notifiedPrice;
    }

    /**
     * The mail's own text -- plain, since nothing here templates HTML mail
     * yet and a one-line alert does not need it to.
     *
     * No link to the route page: building one needs the site's own canonical
     * domain, which this app has never had -- every other page builds its
     * absolute URLs live, from the request that is asking (see
     * `SitemapController::host()`), and a scheduled command has no request
     * to ask. Worth a real config setting, not one invented quietly here.
     */
    private static function body(
        AirportRepository $airports,
        string $from,
        string $to,
        float $total,
        string $departDate,
    ): string {
        $fromCity = $airports->cityByCode($from) ?? $from;
        $toCity = $airports->cityByCode($to) ?? $to;

        return implode("\n", [
            sprintf(
                'A ticket from %s to %s is now %s CAD, on %s.',
                $fromCity,
                $toCity,
                number_format($total, 2),
                $departDate,
            ),
            '',
            "You'll hear from this watch again only if the price drops further.",
        ]);
    }
}
