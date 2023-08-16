<?php
namespace TripBuilder\Noah;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class Init
{
    const DEFAULT_CLASS = 'TripBuilder\Noah';

    const DEFAULT_METHOD = 'run';

    /**
     * @param $args
     * @throws \Exception
     */
    public function __construct($args)
    {
        $this->welcomeMessage();

        $commandParts = explode(':', $args);

        if (count($commandParts) === 2) {
            $class = sprintf(
                '\%s\%s\%s',
                self::DEFAULT_CLASS,
                ucfirst($commandParts[0]),
                ucfirst($commandParts[1])
            );

            // Check if the method exists
            if (method_exists($class, self::DEFAULT_METHOD)) {
                $instance = new $class;

                $method = self::DEFAULT_METHOD;

                $result = $instance->$method();

                echo !empty($result)
                    ? sprintf('%s: %s%s', 'Result', $result, PHP_EOL)
                    : '';
            } else {
                throw new \Exception("Error: Class `$class` or method `$_method()` not found.");
            }
        } else {
            throw new \Exception("Error: Invalid command format. Usage: php cron/index.php param:function");
        }
    }

    /**
     * @return void
     */
    private function welcomeMessage(): void
    {
        $output = new ConsoleOutput();

        $blueStyle = new OutputFormatterStyle('white', 'blue');
        $yellowStyle = new OutputFormatterStyle('black', 'yellow');

        $output->getFormatter()->setStyle('blue', $blueStyle);
        $output->getFormatter()->setStyle('yellow', $yellowStyle);

        $output->writeln('Noah <blue>#StandWith</blue><yellow>Ukraine</yellow>');
    }

}
