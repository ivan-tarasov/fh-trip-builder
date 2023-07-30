<?php
/**
 * Software tests page
 *
 * Show all airlines from DB
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.0.1
 */

include(__DIR__ . '/header.inc.php');

$html .= Functions::template('index', null, 'software-tests');

include(__DIR__ . '/footer.inc.php');
?>
