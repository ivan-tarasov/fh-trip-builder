<?php
/**
 * TripBuilder Config Class
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.2.1
 */

class Config
{
	public static $site = [
		/** Site version ================================================================================================ */
		'version' => ['major' => 1, 'minor' => 4, 'patch' => 17],

		/** Git settings ================================================================================================ */
		'git' => 'https://bitbucket.org/karapuzoff/trip-builder/commits/',

		/** Where MySQL credentials saved =============================================================================== */
		'mysql' => 'config.mysql.php',

		/** Pagination settings: search page and booking page =========================================================== */
		'pagination' => [
			'search' => 7,
			'booking' => 100
		],

		/** Templates for some places =================================================================================== */
		'templates' => [
			'sidebar' => [
				'sort' => 'list-group-checkable'
			]
		],

		/** Main menu items ============================================================================================= */
		'main-menu' => [
			'my-bookings' => [
				'text' => 'My bookings',
				'icon' => 'far fa-address-book',
				'spacer' => 1
			],
			'airlines' => [
				'text' => 'Airlines',
				'icon' => 'fas fa-plane'
			],
			'airports' => [
				'text' => 'Airports',
				'icon' => 'fas fa-map-marked-alt',
				'spacer' => 1
			],
			'software-tests' => [
				'text' => 'Software tests',
				'icon' => 'fas fa-code'
			]
		],

		/** Sorting section of search sidebar =========================================================================== */
		'sort' => [
			'rating' => [
				'id' => 'Popular',
				'text' => 'Popular first',
				'note' => 'First we show tickets with higher rating',
				'order' => 'desc',
				'roundtrip' => 1,
				'oneway' => 1,
				'badge' => [
					'id' => 'rating',
					'text' => 'Top rated',
					'icon' => 'star',
					'color' => 'danger'
				]
			],
			'price' => [
				'id' => 'Price',
				'text' => 'Cheap ones first',
				'note' => 'Easy way to find most cheaper tickets',
				'order' => 'asc',
				'roundtrip' => 1,
				'oneway' => 1,
				'badge' => [
					'id' => 'price',
					'text' => 'Cheapest price',
					'icon' => 'check-circle',
					'color' => 'success'
				]
			],
			'duration' => [
				'id' => 'FlightTime',
				'text' => 'Flight time',
				'note' => 'We show lowest duration flights first',
				'order' => 'asc',
				'roundtrip' => 1,
				'oneway' => 1,
				'badge' => [
					'id' => 'duration',
					'text' => 'Fastest flight',
					'icon' => 'rocket',
					'color' => 'primary'
				]
			],
			'departure_time' => [
				'id' => 'Departure',
				'text' => 'Departure time',
				'note' => 'Tickets with earlier departure time will at the top of the list',
				'order' => 'asc',
				'roundtrip' => 0,
				'oneway' => 1,
				'badge' => [
					'id' => 'departure_time',
					'text' => 'Earlier departure',
					'icon' => 'plane-departure',
					'color' => 'badge-bd-indigo-200'
				]
			],
			'arrival_time' => [
				'id' => 'Arrival',
				'text' => 'Arrival time',
				'note' => 'Tickets with earlier arrival time will at the top of the list',
				'order' => 'asc',
				'roundtrip' => 0,
				'oneway' => 1,
				'badge' => [
					'id' => 'arrival_time',
					'text' => 'Earlier arrival',
					'icon' => 'plane-arrival',
					'color' => 'dark'
				]
			]
		],

		/** Social networks menu items ================================================================================== */
		'footer-social' => [
			'LinkedIn' => [
				'url' => 'https://linkedin.com/in/ivan-tarasov-ca',
				'ico' => 'linkedin'
			],
			'Telegram' => [
				'url' => 'https://t.me/karapuzoff',
				'ico' => 'telegram'
			],
			'Facebook' => [
				'url' => 'https://facebook.com/karapuzoff',
				'ico' => 'facebook'
			],
			'Instagram' => [
				'url' => 'https://instagram.com/karapuzoff',
				'ico' => 'instagram'
			],
			'Twitter' => [
				'url' => 'https://twitter.com/karapuzoff',
				'ico' => 'twitter'
			],
		],

		/** Git menu items ============================================================================================== */
		'footer-git' => [
			'Explore the docs' => 'https://bitbucket.org/karapuzoff/trip-builder/src/develop/README.md',
			'Report Bug' => 'https://bitbucket.org/karapuzoff/trip-builder/issues',
			'Request Feature' => 'https://bitbucket.org/karapuzoff/trip-builder/issues',
			'Pull requests' => 'https://bitbucket.org/karapuzoff/trip-builder/pull-requests/'
		],

		/** Search forms active tab settings (flontend) ================================================================= */
		'tab_active' => [
			'btn' => ' active',
			'aria' => 'true',
			'div' => ' show active'
		]
	];

	public static $setup = [
		/** Setup script settings (e.g. how many flights script generate by default - 10,000) =========================== */
		'flights' => 10000
	];
	/** END OF CONFIG ================================================================================================= */

	public static $mysql;
	public static $api;

	public static function get($name)
	{
		switch ($name) {
			case 'mysql':
				$path_origin = __ROOT__ . '/config/' . self::$site['mysql'];
				$path_sample = __ROOT__ . '/config/' . self::$site['mysql'] . '.sample';

				if (!file_exists($path_origin)) {
					if (!file_exists($path_sample)) {
						$mysql_tmp = "<?php
\$___mysql = [
/** MySQL credentials ================================== */
  'host'  => 'localhost',
  'user'  => 'dbuser',
  'pass'  => 'dbpass',
  'db'    => 'dbname'
/**===================================================== */
];";

						$f = fopen($path_origin, 'wb');
						if (!$f)
							die('Error creating the file ' . $path_origin);

						fputs($f, $mysql_tmp);
						fclose($f);
					} else {
						if (!copy($path_sample, $path_origin))
							echo 'Error while copy sample file to origin';
					}
				}

				require $path_origin;
				self::$mysql = $_mysql;

				break;

			case 'api':
				self::$api = [
					'url' => sprintf('%s://%s/api', self::apiScheme(), self::apiHost()),
					'token' => 'SomeAPItoken_$ecretWORD---orHASH'
				];

				break;
		}

		if (isset(self::$$name))
			return self::$$name;

		return null;
	}

	private static function apiScheme()
	{
		$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';

		return $scheme;
	}

	private static function apiHost()
	{
		return !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
	}
}
