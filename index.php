<?php
/**
 * Index page
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.3
 */

include(__DIR__ . '/header.inc.php');

$params = [
    '%PAGE_TITLE%'        => 'Main page',
    '%MAIN_BG_IMAGE%'     => rand(1, 10),
    '%API_PATH_AIRPORTS%' => Config::get('api')['url'] . '/airports.php'
];

$html .= Functions::template('index', $params, 'index');

include(__DIR__ . '/footer.inc.php');
