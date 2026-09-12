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
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\ScheduleRunRepository;
use TripBuilder\Schedule;

#[AsCommand(
    name: 'schedule:run',
    description: 'Run the scheduled commands that are due. The only thing cron needs to call.',
    aliases: [],
    hidden: false,
)]

/**
 * The one line on the server.
 *
 *     0,15,30,45 * * * * cd /path/to/fh-trip-builder && php noah schedule:run
 *
 * Everything else lives in `config/noah/schedule.php`, which is in git, which
 * is the point: before this the crontab was the only record of what this
 * application runs on a schedule, so a rebuilt server took it with it and
 * nothing here said what had been lost (E16, #167).
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
    protected function configure(): void
    {
        $this->addOption(
            'pretend',
            null,
            InputOption::VALUE_NONE,
            'Say what is due and run none of it.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new DateTimeImmutable();

        try {
            $schedule = Schedule::fromConfig(Helper::getRootDir() . '/config/noah/schedule.php');
            $runs = new ScheduleRunRepository($this->connection());
            $due = $schedule->due($now, $runs->all());
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($due === []) {
            $this->io->text(sprintf('Nothing due at %s.', $now->format('Y-m-d H:i')));

            return Command::SUCCESS;
        }

        foreach ($due as $task) {
            if ($input->getOption('pretend')) {
                $this->formatOutput($task['command'], 'would run', 'comment');

                continue;
            }

            $this->runOne($task['command'], $runs, $output);
        }

        return Command::SUCCESS;
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

        $runs->finished($command, $exit, new DateTimeImmutable()->format('Y-m-d H:i:s'));

        $this->formatOutput(
            $command,
            $exit === Command::SUCCESS ? 'ok' : 'exit ' . $exit,
            $exit === Command::SUCCESS ? 'info' : 'error',
        );
    }
}
