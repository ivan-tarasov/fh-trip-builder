<?php
/**
 * Software setup script
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.0.4
 */

if (!defined('__ROOT__'))
  define('__ROOT__', dirname(__FILE__, 2));

require_once __ROOT__ . '/class/Timer.class.php';
require_once __ROOT__ . '/class/MysqliDb.class.php';
// require_once __ROOT__ . '/class/dBug.class.php';

/** CONFIG class including */
require_once __ROOT__ . '/config/Main.class.php';

/** Quick functions class */
require_once __ROOT__ . '/class/Functions.class.php';

/** Check Software setup state */
if (Functions::checkSetup()) {
  header("Location: /");
  die();
}

$params_setup = [
  '%SOFTWARE_VERSION%' => Config::$site['version'],
  '%FLIGHTS_TO_GENERATE%' => number_format(Config::$setup['flights'])
];
echo Functions::template('header', null, 'terminal') . Functions::template('index', $params_setup, 'setup') . Functions::template('footer', null, 'terminal');
