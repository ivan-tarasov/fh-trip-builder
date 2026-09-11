<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Airside;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Aws\S3;
use TripBuilder\Cdn;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\PostImageRepository;
use TripBuilder\Repository\PostRepository;
use TripBuilder\Service\PostImageSweep;

#[AsCommand(
    name: 'airside:prune',
    description: 'Remove Airside images in the bucket that no post points at any more.',
    aliases: [],
    hidden: false,
)]

/**
 * What the uploader leaves behind.
 *
 * `airside:import` never deletes, and hashed names are why that is safe: a
 * changed image is a new name, so nothing cached is ever stale. What it is not
 * is free. A hero replaced five times leaves five sets of seven variants, and
 * only the newest is reachable.
 *
 * **Lists by default and deletes only with `--force`**, which is the opposite
 * of the importer and deliberate. The staging directory is not committed, so
 * for every one of these files the bucket holds the only copy; a sweep that
 * deleted on sight would turn one wrong answer into a permanent one. Read the
 * list, then run it again.
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
        if (!Cdn::isConfigured()) {
            // Nothing to sweep and nothing to sweep it with: with no
            // distribution the files are served from the staging directory,
            // which is somebody's working copy and not this command's to tidy.
            $this->io->error('No distribution is configured, so there is no bucket to sweep.');

            return Command::FAILURE;
        }

        try {
            $connection = $this->connection();
            $sweep = new PostImageSweep(
                S3::fromEnvironment(),
                new PostRepository($connection),
                new PostImageRepository($connection),
            );

            $keys = $sweep->orphanKeys();
            $rows = $sweep->orphanRows();
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($keys as $key) {
            $this->formatOutput($key, $input->getOption('force') ? 'deleting' : 'would delete', 'comment');
        }

        foreach ($rows as $file) {
            $this->formatOutput($file, $input->getOption('force') ? 'forgetting' : 'would forget', 'comment');
        }

        if ($keys === [] && $rows === []) {
            $this->io->success('Nothing to remove: every object and row is reachable.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('force')) {
            $this->io->note(sprintf(
                '%d object(s) and %d row(s) would be removed. Nothing was. Run again with --force.',
                count($keys),
                count($rows),
            ));

            return Command::SUCCESS;
        }

        try {
            $sweep->remove($keys, $rows);
        } catch (Throwable $e) {
            // Part of it may already be gone. That is recoverable and the next
            // run finishes the job, which is a property of sweeping against
            // what the posts say rather than against a list made earlier.
            $this->io->error('Stopped partway: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->io->success(sprintf('%d object(s) removed, %d row(s) forgotten.', count($keys), count($rows)));

        return Command::SUCCESS;
    }
}
