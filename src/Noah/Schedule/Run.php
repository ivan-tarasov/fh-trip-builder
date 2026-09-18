<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Schedule;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\ScheduledJobRepository;
use TripBuilder\Repository\ScheduleRunRepository;
use TripBuilder\Schedule;

#[AsCommand(
    name: self::NAME,
    description: 'Run the scheduled commands that are due. The only thing cron needs to call.',
    aliases: [],
    hidden: false,
)]

/**
 * The one line on the server.
 *
 *     * * * * * cd /path/to/fh-trip-builder && php noah schedule:run >> ~/logs/schedule.log 2>&1
 *
 * Everything else lives in the `scheduled_jobs` table, edited from
 * `/admin/schedule` (G19, #377) -- before that it was `config/noah/schedule.php`,
 * in git, so a pull request was the only record of what this application runs
 * on a schedule. The point survives the move: a rebuilt server still cannot
 * lose the schedule with it, because it was never on the server to begin with
 * (E16, #167).
 *
 * **This returns SUCCESS even when a task fails.** A non-zero exit here would
 * mean cron mailing the operator about a broken `currency:rates` and, worse,
 * an operator who starts ignoring it. The failure is recorded per command in
 * `schedule_runs`, where E16.2 (#169) reads it, and the next task still runs.
 *
 * The one thing it does fail on is not being able to reach that table, because
 * then it cannot tell what is due, cannot record what it did, and would run
 * every task on every tick.
 */
final class Run extends AbstractCommand
{
    public const string NAME = 'schedule:run';

    private const string OPT_PRETEND = 'pretend';
    private const string OPT_PRETEND_DESCRIPTION = 'Say what is due and run none of it.';

    /** No arguments -- everything here is a flag. */
    public const array ARGUMENTS = [];

    /** Every option this command takes, name => description. */
    public const array OPTIONS = [self::OPT_PRETEND => self::OPT_PRETEND_DESCRIPTION];

    protected function configure(): void
    {
        $this->addOption(
            self::OPT_PRETEND,
            null,
            InputOption::VALUE_NONE,
            self::OPT_PRETEND_DESCRIPTION,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new DateTimeImmutable();

        try {
            $schedule = Schedule::fromRows(new ScheduledJobRepository($this->connection())->allEnabled());
            $runs = new ScheduleRunRepository($this->connection());
            $due = $schedule->due($now, $runs->all());
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption(self::OPT_PRETEND)) {
            $this->report($schedule, $runs->all(), $due, $now);

            return Command::SUCCESS;
        }

        if ($due === []) {
            $this->io->text(sprintf('Nothing due at %s.', $now->format('Y-m-d H:i')));

            return Command::SUCCESS;
        }

        foreach ($due as $task) {
            $this->runOne($task['command'], $runs, $output);
        }

        return Command::SUCCESS;
    }

    /**
     * The whole schedule and how it is doing, without running any of it.
     *
     * This is what somebody checks after a deploy and when something feels
     * stale, so it shows every task rather than only the due ones, and it
     * shows the last success rather than the last attempt -- a command failing
     * every night at 03:00 looks busy by the attempt and is dead by the
     * success, and the second is the answer to the question being asked.
     *
     * @param array<string, array{last_run_at: string, last_success_at: ?string, last_exit: int}> $records
     * @param list<array{command: string, cron: \TripBuilder\Cron}> $due
     */
    private function report(Schedule $schedule, array $records, array $due, DateTimeImmutable $now): void
    {
        $dueCommands = array_column($due, 'command');
        $rows = [];

        foreach ($schedule->tasks() as $task) {
            $record = $records[$task['command']] ?? null;

            $rows[] = [
                $task['command'],
                $task['cron']->expression(),
                $record['last_success_at'] ?? 'never',
                $record === null ? '-' : ($record['last_exit'] === 0 ? 'ok' : 'exit ' . $record['last_exit']),
                in_array($task['command'], $dueCommands, true) ? 'due now' : '',
            ];
        }

        $this->io->table(['command', 'cron', 'last worked', 'last exit', ''], $rows);
        $this->io->text(sprintf(
            '%d of %d due at %s. Nothing was run.',
            count($due),
            count($schedule->tasks()),
            $now->format('Y-m-d H:i'),
        ));
    }

    /**
     * Run one command as though it had been typed, and record how it went.
     */
    private function runOne(string $command, ScheduleRunRepository $runs, OutputInterface $output): void
    {
        // Stamped before it runs. The tick fifteen minutes from now must not
        // pick up something still going, and a task whose process is killed
        // must not retry every quarter hour until somebody notices.
        $runs->started($command, new DateTimeImmutable()->format('Y-m-d H:i:s'));
        $historyId = $runs->historyStarted($command, new DateTimeImmutable()->format('Y-m-d H:i:s'));

        try {
            $application = $this->getApplication();

            if ($application === null) {
                throw new RuntimeException('No application to run ' . $command . ' with.');
            }

            $arguments = new StringInput($command);
            $name = $arguments->getFirstArgument() ?? $command;

            // find()->run() and not Application::run(). `noah` never calls
            // setAutoExit(false), so the application-level run would end the
            // process on the first task and every one after it would be
            // skipped -- silently, because cron would see a clean exit.
            $exit = $application->find($name)->run($arguments, $output);
        } catch (Throwable $e) {
            // A task that threw is a task that failed, not a scheduler that
            // failed. Record it and carry on to the next one.
            $exit = Command::FAILURE;
            $this->io->error(sprintf('%s threw: %s', $command, $e->getMessage()));
        }

        $at = new DateTimeImmutable()->format('Y-m-d H:i:s');
        $runs->finished($command, $exit, $at);
        $runs->historyFinished($historyId, $exit, $at);

        $this->formatOutput(
            $command,
            $exit === Command::SUCCESS ? 'ok' : 'exit ' . $exit,
            $exit === Command::SUCCESS ? 'info' : 'error',
        );
    }
}
