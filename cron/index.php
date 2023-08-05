<?php
/**
 * Cron jobs handler
 *
 * @version 0.0.2
 */

// Default class path
$_class  = 'TripBuilder\Cron';

// Default method
$_method = 'run';

try {
    require_once 'vendor/autoload.php';

    if (isset($argv[1])) {
        $commandParts = explode(':', $argv[1]);

        if (count($commandParts) === 2) {
            $class = sprintf(
                '\%s\%s\%s',
                $_class,
                ucfirst($commandParts[0]),
                ucfirst($commandParts[1])
            );

            // Check if the method exists
            if (method_exists($class, $_method)) {
                $instance = new $class;

                $result = $instance->$_method();

                echo "Result: " . $result . "\n";
            } else {
                throw new Exception("Error: Class `$class` or method `$_method()` not found.");
            }
        } else {
            throw new Exception("Error: Invalid command format. Usage: php cron/index.php param:function");
        }
    } else {
        throw new Exception("Error: No command provided.");
    }
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
}