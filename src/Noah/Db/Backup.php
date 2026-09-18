<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Db;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Database\Dump;
use TripBuilder\Env;
use TripBuilder\EnvKey;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name: self::NAME,
    description: 'Write a gzipped mysqldump of the whole database.',
    aliases: [],
    hidden: false,
)]

/**
 * The thing you wish you had run five minutes ago.
 *
 * E14 (#159) already said to take a backup before a deploy, as a `mysqldump`
 * line with the credentials retyped by hand. That is a backup you have to
 * remember at the moment you are least likely to -- and on 2026-09-12 a test
 * deleted eight real bookings out of a development database that had none
 * (E18, #176).
 *
 * Credentials come from the environment, read through `Env::get()` so this and
 * the application cannot disagree about which database they mean.
 */
final class Backup extends AbstractCommand
{
    public const string NAME = 'db:backup';
    public const string DIRECTORY = 'backups';

    private const string OPT_BINARY = 'binary';

    protected function configure(): void
    {
        $this->addOption(
            self::OPT_BINARY,
            null,
            InputOption::VALUE_REQUIRED,
            'Path to mysqldump, when it is not on PATH.',
            'mysqldump',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $binary */
        $binary = $input->getOption(self::OPT_BINARY);

        if (!Dump::isAvailable($binary)) {
            $this->io->error(sprintf(
                '`%s` is not on PATH. Pass --binary=/full/path/to/mysqldump.',
                $binary,
            ));

            return Command::FAILURE;
        }

        $database = Env::get(EnvKey::DbDatabase);

        if ($database === '') {
            $this->io->error('DB_DATABASE is empty, so there is nothing to back up.');

            return Command::FAILURE;
        }

        $dump = new Dump($binary, $database, Dump::supportsColumnStatistics($binary));
        $path = $this->destination($dump->fileName(new DateTimeImmutable()->format('Y-m-d-His')));

        try {
            $written = $this->writeDump($dump, $path);
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            // A half-written dump is worse than none: it looks like a backup.
            if (is_file($path)) {
                unlink($path);
            }

            return Command::FAILURE;
        }

        $this->io->success(sprintf('%s (%s)', $path, self::readable($written)));

        return Command::SUCCESS;
    }

    /**
     * Run the dump, and return how many bytes landed.
     *
     * The credentials file is removed in `finally`, so a dump that fails
     * partway does not leave a password on disk.
     */
    private function writeDump(Dump $dump, string $path): int
    {
        $defaults = Dump::writeDefaults(Dump::defaults(
            Env::get(EnvKey::DbHost),
            Env::get(EnvKey::DbPort) ?: '3306',
            Env::get(EnvKey::DbUsername),
            Env::get(EnvKey::DbPassword),
        ));

        try {
            $command = sprintf(
                '%s | gzip > %s',
                implode(' ', array_map(escapeshellarg(...), $dump->arguments($defaults))),
                escapeshellarg($path),
            );

            // PIPESTATUS, because the exit code of a pipeline is gzip's, and
            // gzip is perfectly happy to compress nothing at all.
            exec('bash -c ' . escapeshellarg($command . '; exit ${PIPESTATUS[0]}'), $lines, $exit);

            if ($exit !== 0) {
                throw new RuntimeException(sprintf('mysqldump exited %d.', $exit));
            }
        } finally {
            unlink($defaults);
        }

        $size = is_file($path) ? (int) filesize($path) : 0;

        if ($size === 0) {
            throw new RuntimeException('The dump is empty.');
        }

        return $size;
    }

    /**
     * `backups/` and not `cache/`, because E14 §8 empties `cache/` on deploy
     * and a backup is not a cache.
     */
    private function destination(string $file): string
    {
        $directory = Helper::getRootDir() . '/' . self::DIRECTORY;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create ' . $directory);
        }

        return $directory . '/' . $file;
    }

    private static function readable(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => $bytes . ' B',
            $bytes < 1048576 => round($bytes / 1024) . ' KB',
            default => round($bytes / 1048576, 1) . ' MB',
        };
    }
}
