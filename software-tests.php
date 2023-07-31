<?php
/**
 * Software tests page
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.0.3
 */

include(__DIR__ . '/header.inc.php');

$html .= Functions::template('index', null, 'software-tests');

include(__DIR__ . '/footer.inc.php');
