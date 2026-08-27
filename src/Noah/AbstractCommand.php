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

abstract class AbstractCommand extends Command
{
    private const NOAH_VERSION = 'v0.3.4';

    /**
     * Color presets
     *
     * Format: TAG => [FOREGROUND, BACKGROUND]
     *
     * Colors: default
     *         black, red,        green,        yellow,        blue,        magenta,        cyan,        white,
     *         gray,  bright-red, bright-green, bright-yellow, bright-blue, bright-magenta, bright-cyan, bright-white
     */
    private const STYLE_FORMATS = [
        'default' => ['default', 'default'],
        'primary' => ['white', 'blue'],
        'success' => ['bright-white', 'green'],
        'danger' => ['white', 'red'],
        'warning' => ['black', 'yellow'],
        'info' => ['bright-white', 'cyan'],
        'light' => ['black', 'white'],
        'dark' => ['white', 'black'],
    ];

    private const FORMAT_OUTPUT_LINE_LENGTH = 60;
    private const FORMAT_OUTPUT_DEFAULT_COLOR = 'default';

    protected const CONFIG_DIR_TABLES  = 'noah/db/tables';
    protected const CONFIG_DIR_SEEDERS = 'noah/db/seeders';

    protected InputInterface $input;
    protected OutputInterface $output;
    protected SymfonyStyle $io;

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
     * @throws Exception
     */
    protected function getAllDatabaseTables(): array
    {
        $rows = $this->connection()->fetchAll(
            'SELECT table_name AS name FROM information_schema.tables'
            . ' WHERE table_schema = ? AND table_type = ?',
            [$_ENV['DB_DATABASE'] ?? '', 'BASE TABLE'],
        );

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
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
