<?php
/**
 * Script or operation execution time
 *
 * Script shows how many seconds and milliseconds your code was executed
 *
 * @example   https://tarasov.ca/code/executetimer/
 *
 * @return    string  Seconds and milliseconds in pre-formating or pure version
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2019-2023
 * @version   0.1.10
 */

class Timer
{
  /**
   * @var float execution start time
   */
    private static $start = .0;

  /**
   * Start execution
   */
    static function start()
    {
        self::$start = microtime(true);
    }

  /**
   * Difference between the current timestamp and self::$start
   * @return float
   */
    static function finish($pre = false)
    {
        // LOCAL
        $out = round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 3);
        // TODO: For testing proposition - REMOTE
        // $out = round(microtime(true) - self::$start, 3);

        return (!empty($pre) ? '<pre><i class="fa-lg fa-regular fa-clock"></i> ' . $out . ' seconds</pre>' : $out);
    }

}
