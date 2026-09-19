<?php

declare(strict_types=1);

namespace TripBuilder\Noah;

use Dotenv\Dotenv;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TripBuilder\Database\Connection;
use TripBuilder\Helper;

/**
 * @phpstan-type TableNameRow array{name: string}
 */
abstract class AbstractCommand extends Command
{
    /**
     * Printed at the top of every command, and the only way to tell which Noah
     * is on a server.
     *
     * **Move it when Noah moves.** A new command is a minor, a fix to one is a
     * patch, and removing or renaming one is a major -- the crontab and E14
     * (#159) name commands, so a rename is a breaking change to somebody's
     * server rather than to a library.
     *
     * It said `v0.3.4` from 2025-08-25 until 2026-09-12, during which eleven
     * commands were added and the CLI went from four to fifteen. The bumps
     * before that were all real, so the habit existed and lapsed once already
     * (E22, #184) -- and then lapsed a second time: `alerts:check`'s own
     * addition was the last commit to touch this line, and `cities:images`
     * (C10, #395) landed after it, plus three more PRs fixing that one
     * command, with nothing here moving through any of them.
     *
     * `v1.7.0` and not `v1.6.3`, which is what one new command plus three
     * fixes to it would count to piecemeal. The number should say what is
     * true, the same reasoning `v1.0.0` was picked on: this is the tool a
     * production deploy is built on -- cron calls `schedule:run`, `db:clear`
     * refuses to run without `db:backup`, and the runbook is made of it. A
     * command that did not exist in `v1.6.0` existing now is what matters;
     * how many pull requests it took to get right is not.
     *
     * Enforced now, the second time this lapsed being reason enough to
     * revisit a call the docblock used to record as settled: a required CI
     * check (`.github/workflows/noah-version.yml`) fails a pull request that
     * touches `src/Noah/` or the `noah` bootstrap without this constant's own
     * value also moving, the same way `pr-labels.yml` already blocks a merge
     * with no release label.
     *
     * `v1.7.1`: the check's own first real catch, on the pull request that
     * made it necessary to write down again -- a further fix to
     * `cities:images` (requesting a wider Wikipedia thumbnail) opened before
     * `v1.7.0` existed, and never carrying its own bump until the CI check
     * above said so.
     *
     * `v1.8.0`: `countries:content` (C17, #409), a new command -- built
     * alongside C16 (#408), which pulled the fetch/backoff/disambiguation
     * logic this and `cities:images` now both share out of the latter.
     *
     * `v2.0.0`: `cities:images` renamed to `cities:content` -- once
     * `countries:content` existed right beside it, a name that only said
     * "images" read as confusing about a command that had cached a text
     * summary since C15 (#405). A rename is a major on purpose: the
     * crontab and E14 (#159) name commands, so this is a breaking change
     * to somebody's server rather than to a library -- and here it broke
     * a real one, `scheduled_jobs`' own already-seeded row (migration
     * `0011`), fixed by a follow-up migration rather than editing that
     * one's history.
     */
    private const string NOAH_VERSION = 'v2.0.0';

    /**
     * Color presets
     *
     * Format: TAG => [FOREGROUND, BACKGROUND]
     *
     * Colors: default
     *         black, red,        green,        yellow,        blue,        magenta,        cyan,        white,
     *         gray,  bright-red, bright-green, bright-yellow, bright-blue, bright-magenta, bright-cyan, bright-white
     */
    private const array STYLE_FORMATS = [
        'default' => ['default', 'default'],
        'primary' => ['white', 'blue'],
        'success' => ['bright-white', 'green'],
        'danger' => ['white', 'red'],
        'warning' => ['black', 'yellow'],
        'info' => ['bright-white', 'cyan'],
        'light' => ['black', 'white'],
        'dark' => ['white', 'black'],
    ];

    private const int FORMAT_OUTPUT_LINE_LENGTH = 60;
    private const string FORMAT_OUTPUT_DEFAULT_COLOR = 'default';

    protected const string CONFIG_DIR_TABLES = 'noah/db/tables';
    protected const string CONFIG_DIR_SEEDERS = 'noah/db/seeders';

    // Console I/O handles: set once by initialize(), read-only for commands.
    protected private(set) InputInterface $input;
    protected private(set) OutputInterface $output;
    protected private(set) SymfonyStyle $io;

    private ?Connection $connection = null;

    protected function connection(): Connection
    {
        return $this->connection ??= Connection::fromEnv();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        // Setting up input and output global variables
        $this->input = $input;
        $this->output = $output;

        // Define IO instance
        $this->io = new SymfonyStyle($input, $output);

        // Building style formats
        $this->buildFormats();

        // Show welcome message
        $this->headerMessage();

        // Show command information
        $this->commandInformation();

        // Load environment variables so Connection::fromEnv() has DB_* available
        $this->loadEnvironment();
    }

    private function headerMessage(): void
    {
        $this->io->writeln('
 ███╗   ██╗ ██████╗  █████╗ ██╗  ██╗
 ████╗  ██║██╔═══██╗██╔══██╗██║  ██║
 ██╔██╗ ██║██║   ██║███████║███████║
 ██║╚██╗██║██║   ██║██╔══██║██╔══██║
 ██║ ╚████║╚██████╔╝██║  ██║██║  ██║
 ╚═╝  ╚═══╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝ ' . self::NOAH_VERSION);
        $this->io->writeln(
            sprintf(
                ' <primary>%s</primary><warning>%s</warning>',
                '#StandWith',
                'Ukraine',
            ),
        );

        $this->io->newLine();
    }

    private function commandInformation(): void
    {
        $this->io->writeln(
            sprintf(
                ' <info>  %s </info>',
                $this->getDescription(),
            ),
        );

        $this->io->newLine();
    }

    private function loadEnvironment(): void
    {
        Dotenv::createImmutable(Helper::getRootDir())->load();
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    protected function getAllDatabaseTables(): array
    {
        /** @var list<TableNameRow> $rows */
        $rows = $this->connection()->fetchAll(
            // DATABASE() rather than $_ENV -- see the note in Install::tableExists().
            'SELECT table_name AS name FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_type = ?',
            ['BASE TABLE'],
        );

        return array_map(self::tableName(...), $rows);
    }

    /** @param TableNameRow $row */
    private static function tableName(array $row): string
    {
        return $row['name'];
    }

    private function buildFormats(): void
    {
        foreach (self::STYLE_FORMATS as $name => $colors) {
            $style = new OutputFormatterStyle($colors[0], $colors[1]);
            $this->io->getFormatter()->setStyle($name, $style);
        }
    }

    protected function formatOutput(
        string $text,
        string $status,
        string $color = self::FORMAT_OUTPUT_DEFAULT_COLOR,
        bool $newline = false,
    ): void {
        $times = mb_strlen($status);

        $dots = str_repeat('.', max(0, self::FORMAT_OUTPUT_LINE_LENGTH - mb_strlen($text) - $times));

        $this->output->writeln(
            sprintf(
                ' %1$s %2$s <%4$s> %3$s </%4$s>',
                $text,
                $dots,
                strtoupper($status),
                $color,
            ),
        );

        if ($newline) {
            $this->io->newLine();
        }
    }
}
