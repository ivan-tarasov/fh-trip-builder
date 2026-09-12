<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Db;

use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Repository\RateLimitRepository;

#[AsCommand(
    name: 'db:prune',
    description: 'Remove rows the retention policy says are no longer kept.',
    aliases: [],
    hidden: false,
)]

/**
 * What the application is not allowed to keep.
 *
 * Two sweeps, because they are one cron line and one decision -- how long
 * something is kept -- applied to two tables:
 *
 * **Bookings** past `BookingRepository::KEEP_DAYS_AFTER_DEPARTURE`, and the
 * passengers travelling on them. These hold an email, a phone number, names,
 * dates of birth and genders, and before this nothing in the codebase ever
 * deleted one: `db:clear` empties tables and is not a policy. For a site
 * priced in CAD and addressed to Canadians that is PIPEDA scope, which asks
 * that personal information be kept only as long as it is needed (E9, #146).
 *
 * **Rate-limit counters** for hours that have passed. Housekeeping rather than
 * privacy -- a counter is an IP and a number -- but it is the same sweep and
 * the same cron, which is where E8.3 (#145) said it would live.
 *
 * Named `db:prune` and not `bookings:prune` as #146 suggested, because the
 * rate-limit counters are not bookings and a command named for one table that
 * sweeps another reads wrong. It sits beside `db:clear`, `db:install` and
 * `db:migrate`, which is where a third retention rule will want to go.
 *
 * **Lists by default and deletes only with `--force`**, like `airside:prune`
 * and for a stronger reason: this is the one command in the application whose
 * whole purpose is destroying data that has no other copy.
 */
final class Prune extends AbstractCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Actually delete. Without this the command only says what it would remove.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $cutoff = self::cutoff();

        $this->io->text(sprintf(
            'Keeping bookings for %d days after departure, so anything that left before %s goes.',
            BookingRepository::KEEP_DAYS_AFTER_DEPARTURE,
            $cutoff,
        ));

        try {
            $connection = $this->connection();
            $bookings = new BookingRepository($connection);
            $expired = $bookings->departedBefore($cutoff);
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($expired as $booking) {
            $this->formatOutput(
                sprintf('%s (departed %s)', $booking['reference'], $booking['departure_time']),
                $force ? 'forgetting' : 'would forget',
                'comment',
            );
        }

        if (!$force) {
            $this->io->note(sprintf(
                '%d booking(s) would be forgotten, with their passengers, and finished rate-limit '
                . 'counters with them. Nothing was. Run again with --force.',
                count($expired),
            ));

            return Command::SUCCESS;
        }

        try {
            $removed = $bookings->forgetDepartedBefore($cutoff);
            $counters = new RateLimitRepository($connection)->prune();
        } catch (Throwable $e) {
            $this->io->error('Stopped partway: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->io->success(sprintf(
            '%d booking(s) and %d passenger(s) forgotten, %d rate-limit counter(s) dropped.',
            $removed['bookings'],
            $removed['passengers'],
            $counters,
        ));

        return Command::SUCCESS;
    }

    /**
     * The moment a booking stops being kept, as the database spells it.
     */
    private static function cutoff(): string
    {
        return new DateTimeImmutable()
            ->modify(sprintf('-%d days', BookingRepository::KEEP_DAYS_AFTER_DEPARTURE))
            ->format('Y-m-d H:i:s');
    }
}
