<?php
/**
 * Header
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.3
 */

if (!defined('__ROOT__')) {
    define('__ROOT__', dirname(__FILE__));
}

require_once __ROOT__ . '/class/Timer.class.php';
require_once __ROOT__ . '/class/MysqliDb.class.php';

// CONFIG class including
require_once __ROOT__ . '/config/Main.class.php';

// Quick functions class
require_once __ROOT__ . '/class/Functions.class.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    $_SESSION['checktime'] = time();
}

// Check Software setup state
if (!Functions::checkSetup()) {
    header("Location: /setup/");
    die();
}

/**
 * Build main menu
 */
$html_mainmenu = null;

foreach (Config::$site['main-menu'] as $link => $params) {
    $params_mainmenu = [
        '%MENU_ITEM_LINK%'   => $link,
        '%CURRENT_PAGE%'     => $_SERVER['SCRIPT_NAME'] == sprintf('/%s.php', $link) ? 'white' : 'secondary',
        '%MENU_ITEM_TEXT%'   => $params['text'],
        '%MENU_ITEM_ICON%'   => $params['icon'],
        '%MENU_ITEM_SPACER%' => $params['spacer'] ?? 2
    ];

    $html_mainmenu .= Functions::template('menu-item', $params_mainmenu, 'header');
}

$params = [
    '%PAGE_TITLE%'        => 'Main page',
    '%HEADER_MENU_ITEMS%' => $html_mainmenu,
    '%METRIKA_COUNTERS%'  => Functions::template('counters', null, 'header')
];

$html = Functions::template('header', $params, 'index');
