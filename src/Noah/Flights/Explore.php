<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\RoutePriceRepository;
use TripBuilder\Repository\RouteRepository;

/**
 * Keep `route_day_price` warm for the homepage's "Explore" prices (C8, #157).
 *
 * `RoutePriceRepository::build()` otherwise only runs lazily, the first time
 * somebody opens a calendar for that exact route -- fine for a route page,
 * not for a homepage card that cannot afford to wait on it. This pays that
 * cost ahead of time, in the background, for the routes the homepage cards
 * can actually land on: every POI in `Config::get('site.poi')` as the
 * destination, from every origin `RouteRepository::searched()` shows real
 * demand for -- not every sellable airport, which would be most of the 266
 * `AirportRepository::pickable()` offers and mostly for places nobody has
 * ever asked to fly from.
 *
 * Same window, staleness and lock as `AjaxController`'s own calendar build,
 * so the two share one cache rather than keeping two: a route this warms is
 * a route a calendar opens instantly, and the other way round.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Build route_day_price for the homepage Explore cards.',
    aliases: [],
    hidden: false,
)]
class Explore extends AbstractCommand
{
    public const string NAME = 'flights:explore';

    private const string OPT_DRY_RUN = 'dry-run';
    private const string OPT_DRY_RUN_DESCRIPTION = 'List the routes that would be built without building any of them.';

    /** No arguments -- everything here is a flag. */
    public const array ARGUMENTS = [];

    /** Every option this command takes, name => description. */
    public const array OPTIONS = [
        self::OPT_DRY_RUN => self::OPT_DRY_RUN_DESCRIPTION,
    ];

    /** Same as `AjaxController::PRICE_WINDOW_DAYS` -- one cache, not two. */
    private const int WINDOW_DAYS = 90;

    /** Same as `AjaxController::PRICE_MAX_AGE_HOURS`. */
    private const int MAX_AGE_HOURS = 24;

    protected function configure(): void
    {
        $this->addOption(
            self::OPT_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            self::OPT_DRY_RUN_DESCRIPTION,
        );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The site config, which a console command does not otherwise have --
        // AbstractCommand loads the environment but constructs no Config, the
        // same gap Currency\Rates works around. Without this, poiDestinations()
        // reads Config::get('site.poi') as null and the command fails outright
        // rather than quietly building nothing.
        new Config();

        $pairs = $this->pairs();

        $this->formatOutput('Origin x destination pairs', number_format(count($pairs)), 'info');

        if ($input->getOption(self::OPT_DRY_RUN)) {
            foreach ($pairs as [$from, $to]) {
                $this->io->writeln(sprintf(' %s -> %s', $from, $to));
            }

            $this->io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $prices = new RoutePriceRepository($this->connection());
        $since = date('Y-m-d');
        $until = date('Y-m-d', strtotime('+' . self::WINDOW_DAYS . ' day'));

        $built = 0;
        $progress = $this->io->createProgressBar(count($pairs));
        $progress->start();

        foreach ($pairs as [$from, $to]) {
            if ($this->buildOnce($from, $to, $since, $until, $prices)) {
                $built++;
            }

            $progress->advance();
        }

        $progress->finish();
        $this->io->newLine(2);

        $this->formatOutput('Routes built', number_format($built), 'info');
        $this->formatOutput('Already warm', number_format(count($pairs) - $built), 'comment', true);

        return Command::SUCCESS;
    }

    /**
     * Every (origin, destination) worth keeping warm: real demand crossed
     * with the homepage's own destination list, self-pairs dropped.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(): array
    {
        $destinations = self::poiDestinations();

        /** @var list<string> $origins */
        $origins = array_values(array_unique(
            array_column(new RouteRepository($this->connection())->searched(), 'from_code'),
        ));

        $pairs = [];

        foreach ($origins as $from) {
            foreach ($destinations as $to) {
                if ($from !== $to) {
                    $pairs[] = [$from, $to];
                }
            }
        }

        return $pairs;
    }

    /**
     * The homepage's own destinations, deduplicated -- two POI entries
     * sharing a city code would otherwise build the same route twice.
     *
     * @return list<string>
     */
    private static function poiDestinations(): array
    {
        /** @var list<array{code: string}> $poi */
        $poi = Config::get('site.poi');

        return array_values(array_unique(array_column($poi, 'code')));
    }

    /**
     * Build one route, unless it is already warm or somebody else is
     * building it right now -- the same lock `AjaxController`'s calendar
     * endpoint takes, since the two can race for the same route.
     */
    private function buildOnce(string $from, string $to, string $since, string $until, RoutePriceRepository $prices): bool
    {
        if (!$prices->isStale($from, $to, CabinClass::Economy, self::MAX_AGE_HOURS)) {
            return false;
        }

        $name = 'route_prices_' . $from . '_' . $to . '_' . CabinClass::Economy->value;
        $connection = $this->connection();

        /** @var int|null $acquired */
        $acquired = $connection->fetchValue('SELECT GET_LOCK(?, 0)', [$name], 0);

        if ($acquired !== 1) {
            return false;
        }

        try {
            $prices->build($from, $to, CabinClass::Economy, $since, $until);
        } finally {
            $connection->fetchValue('SELECT RELEASE_LOCK(?)', [$name]);
        }

        return true;
    }
}
