<?php

namespace TripBuilder\Noah;

use Dotenv\Dotenv;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TripBuilder\Helper;

abstract class AbstractCommand extends Command
{
    const NOAH_VERSION = 'v0.3.1';

    /**
     * Color presets
     *
     * Format: TAG => [FOREGROUND, BACKGROUND]
     *
     * Colors: default
     *         black, red,        green,        yellow,        blue,        magenta,        cyan,        white,
     *         gray,  bright-red, bright-green, bright-yellow, bright-blue, bright-magenta, bright-cyan, bright-white
     */
    const STYLE_FORMATS = [
        'default'   => ['default', 'default'],
        'primary'   => ['white', 'blue'],
        'success'   => ['bright-white', 'green'],
        'danger'    => ['white', 'red'],
        'warning'   => ['black', 'yellow'],
        'info'      => ['bright-white', 'cyan'],
        'light'     => ['black', 'white'],
        'dark'      => ['white', 'black'],
    ];

    const INDICATOR_SPINNER = [
        ['⠏', '⠛', '⠹', '⢸', '⣰', '⣤', '⣆', '⡇'],
        ['▁','▂','▃','▄','▅','▆','▇','█','▇','▆','▅','▄','▃','▁'],
        ['◜','◝','◞','◟'],
        ['◐','◓','◑','◒'],
    ];

    const FORMAT_OUTPUT_LINE_LENGTH   = 60,
          FORMAT_OUTPUT_DEFAULT_COLOR = 'default';

    protected $input;

    protected $output;

    protected $io;

    protected $db;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        // Setting-up input and output global variables
        $this->input  = $input;
        $this->output = $output;

        // Define IO instance
        $this->io = new SymfonyStyle($input, $output);

        // Building style formats
        $this->buildFormats();

        // Show welcome message
        $this->headerMessage();

        // Connecting to MySQL database
        $this->databaseConnect();
    }

    /**
     * @return void
     */
    private function headerMessage(): void
    {
        $this->io->writeln('
 ███╗   ██╗ ██████╗  █████╗ ██╗  ██╗
 ████╗  ██║██╔═══██╗██╔══██╗██║  ██║
 ██╔██╗ ██║██║   ██║███████║███████║
 ██║╚██╗██║██║   ██║██╔══██║██╔══██║
 ██║ ╚████║╚██████╔╝██║  ██║██║  ██║
 ╚═╝  ╚═══╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝ ' . self::NOAH_VERSION);
        $this->io->writeln(sprintf(
            ' <primary>%s</primary><warning>%s</warning>',
            '#StandWith',
            'Ukraine'
        ));

        $this->io->newLine();
    }

    /**
     * @return void
     */
    private function databaseConnect(): void
    {
        $dotenv = Dotenv::createImmutable(Helper::getRootDir());
        $dotenv->load();

        $this->db = new \MysqliDb(
            '127.0.0.1',
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            $_ENV['DB_DATABASE'],
            $_ENV['DB_PORT']
        );

        // Enable tracer
        $this->db->setTrace (true);
    }

    /**
     * @return array
     */
    protected function getAllDatabaseTables(): array
    {
        $request = sprintf(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = '%s' AND table_type = 'BASE TABLE'",
            $_ENV['DB_DATABASE']
        );

        $response = $this->db->rawQuery($request);

        $tables = [];

        foreach ($response as $table) {
            if (isset($table['table_name'])) {
                $tables[] = $table['table_name'];
            }
        }

        return $tables;
    }

    /**
     * @return void
     */
    private function buildFormats(): void
    {
        foreach (self::STYLE_FORMATS as $name => $colors) {
            $style = new OutputFormatterStyle($colors[0], $colors[1]);
            $this->io->getFormatter()->setStyle($name, $style);
        }
    }

    /**
     * @param string $text
     * @param string $status
     * @param string $color
     * @return void
     */
    protected function formatOutput(
        string $text,
        string $status,
        string $color = self::FORMAT_OUTPUT_DEFAULT_COLOR,
        bool $newline = false
    ): void
    {
        $times = strlen($status);

        $dots = str_repeat('.', self::FORMAT_OUTPUT_LINE_LENGTH - strlen($text) - $times);

        $this->output->writeln(sprintf(
            ' %1$s %2$s <%4$s> %3$s </%4$s>',
            $text,
            $dots,
            strtoupper($status),
            $color
        ));

        if ($newline) {
            $this->io->newLine();
        }
    }

}
