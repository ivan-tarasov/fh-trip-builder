<?php
/**
 * API => Airports autocomplete
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.1
 */

ob_clean();
header_remove();
header("Content-type: application/json; charset=utf-8");

if (!defined('__ROOT__'))
  define('__ROOT__', dirname(__FILE__, 2));

require_once __ROOT__ . '/class/MysqliDb.class.php';
require_once __ROOT__ . '/class/dBug.class.php';

/** CONFIG class including */
require_once __ROOT__ . '/config/Main.class.php';

/** Quick functions class */
require_once __ROOT__ . '/class/Functions.class.php';

try {
  Functions::DBCheck();

	$columns = [
		'a.code',
		'a.title AS airport',
		'a.city_code',
		'a.city',
		'c.title AS country'
	];

  Functions::$db->where('a.enabled', 1);
  Functions::$db->join("countries c", "a.country_code=c.code", "LEFT");
	Functions::$db->orderBy("a.city");
  $airports = Functions::$db->get("airports a", null, $columns);

	$airports_groups = [];

	foreach ($airports as $airport) {
		$airports_groups[$airport['city']]['code'] = $airport['city_code'];
		$airports_groups[$airport['city']]['country'] = $airport['country'];
		$airports_groups[$airport['city']]['airports'][] = $airport;
	}

	$json = [];
	$hidden_format = '<span class="hidden">%1$s|%2$s|%3$s|%4$s</span>';

	foreach ($airports_groups as $city => $group) {
		$json[] = sprintf(
			$hidden_format . '
			<span class="list-group-item list-group-item-action d-flex gap-3 py-2">
				<div class="d-flex gap-2 w-100 justify-content-between">
					<div>
						<span class="mb-0"><strong>%2$s</strong><span class="small">, %3$s</span></span>
					</div>
					<small class="opacity-50 text-nowrap">%1$s</small>
				</div>
			</span>',
			// %1$s – City code
			$group['code'],
			// %2$s – City
			$city,
			// %3$s – Country
			$group['country'],
			0
		);

		foreach ($group['airports'] as $airport) {
			$json[] = sprintf(
				$hidden_format . '
				<span class="list-group-item list-group-item-action d-flex gap-3 py-0">
					<i class="fas fa-lg fa-plane-departure pt-1"></i>
					<div class="d-flex gap-2 w-100 justify-content-between">
						<div>
							<span class="mb-0"><strong>%2$s</strong></span>
							<p class="mb-0 opacity-75 small">%3$s, %4$s</p>
						</div>
						<small class="opacity-50 text-nowrap">%1$s</small>
					</div>
				</span>',
				// %1$s – Airport IATA code
				$airport['code'],
				// %2$s – Airport name
				$airport['airport'],
				// %3$s – Airport city
				$airport['city'],
				// %4$s – Airport country
				$airport['country']
			);
		}
	}

  http_response_code(200);
  echo json_encode($json, JSON_PRETTY_PRINT);
} catch (Exception $e) {
  echo 'Message: ' . $e->getMessage();
  http_response_code(500);
}
