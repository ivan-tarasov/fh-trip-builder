<?php
/**
 * Just quick functions for FlightGub PHP Coding Assignment
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.2
 */

class Functions
{
  public static $db;

  /**
   * Check MySQL connection
   *
   * NOT DOCUMENTED YET
   */
  public static function DBCheck()
  {
    /** DB credentials */
    try {
      /** MySQL init */
      self::$db = new MysqliDb(Config::get('mysql')['host'], Config::get('mysql')['user'], Config::get('mysql')['pass'], Config::get('mysql')['db']);
      self::$db->checkConnect();
    } catch (Exception $e) {
      echo Functions::template('header', null, 'terminal') . Functions::template('db-credentials', null, 'error') . Functions::template('footer', null, 'terminal');
      die();
    }
  }

  /**
   * Check software installation
   *
   * @version 0.0.2
   */
  public static function checkSetup()
  {
    self::DBCheck();

    $installed = true;

    $db_tables = ['airlines', 'airports', 'bookings', 'countries', 'flights'];

    foreach ($db_tables as $table) {
      if (!self::$db->tableExists($table))
        return false;
    }

    return true;
  }

  /**
   * Simple templater
   *
   * @version 1.3.2
   */
  public static function template($tpl, $replace = null, $folder = null)
  {
    $___scriptsFolder = __ROOT__ . '/tpl';

    $folder = !empty($folder) ? $folder . '/' : null;

    $tpl = $___scriptsFolder . '/' . $folder . $tpl . '.tpl';
    $tpl = file_get_contents($tpl, true);

    if (!empty($replace))
      $tpl = strtr($tpl, $replace);

    return $tpl;
  }

  /**
   * Return git information
   *
   * @version 0.3.2
   */
  public static function gitInfo()
  {
    $git_branch = 'git rev-parse --abbrev-ref HEAD';
    $git_tag = 'git describe --tags --abbrev=0';
    $git_commit_hash = 'git log --pretty="%h" -n1 HEAD';
    $git_commit_date = 'git log -n1 --pretty=%ci HEAD';

    $git_branch = trim(exec($git_branch));
    $git_tag = trim(exec($git_tag));
    $git_commit_hash = trim(exec($git_commit_hash));

    $git_commit_date = new \DateTime(trim(exec($git_commit_date)));
    $git_commit_date->setTimezone(new \DateTimeZone('UTC'));

    $git_info = [
      'branch' => $git_branch,
      'tag' => $git_tag,
      'commit_hash' => $git_commit_hash,
      'commit_date' => $git_commit_date->format('Y-m-d H:i:s')
    ];

    return $git_info;
  }

  /**
   * Distance between two points on Earth using the Vincenty formula
   *
   * @version   0.1.8
   *
   * @param float $latFrom			Start point latitude (degrees decimal)
   * @param float $lonFrom			Start point longitude (degrees decimal)
   * @param float $latTo				End point latitude (degrees decimal)
   * @param float $lonTo				End point longitude (degrees decimal)
   * @param float $earthRadius	Earth radius (metres)
   *
   * @return float Distance between points in metres
   */
  public static function earthDistance($latFrom, $lonFrom, $latTo, $lonTo, $earthRadius = 6371000)
  {
    $latFrom = deg2rad($latFrom);
    $lonFrom = deg2rad($lonFrom);
    $latTo = deg2rad($latTo);
    $lonTo = deg2rad($lonTo);

    $lonDelta = $lonTo - $lonFrom;
    $a = pow(cos($latTo) * sin($lonDelta), 2) +
      pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
    $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

    $angle = atan2(sqrt($a), $b);
    $distance = $angle * $earthRadius;

    return $distance;
  }

  /**
   * Flight duration
   *
   * @version   0.1.8
   *
   * @param float $distance			Flight distance (km)
   * @param float $speed				Average cruising speed (km/h)
   *
   * @return float Distance between points in metres
   */
  public static function distance2duration($distance, $format = 'm', $speed = [750, 900])
  {
    $time = $distance / rand($speed[0], $speed[1]);
    $hours = intval($time);
    $minutes = ($time - $hours) * 60;

    switch ($format) {
      case 'm':
        $return = round($time * 60);
        break;
      case 'h':
        $return = $hours;
        break;
    }

    return $return;
  }

  /**
   * Seconds to formatted time
   *
   * NOT DOCUMENTED YET
   */
  public static function secondsToTime($minutes)
  {
    $seconds = $minutes * 60;

    $dtF = new \DateTime('@0');
    $dtT = new \DateTime("@$seconds");

    $dt['d'] = $dtF->diff($dtT)->format('%a');
    $dt['h'] = $dtF->diff($dtT)->format('%h');
    $dt['m'] = $dtF->diff($dtT)->format('%i');

    $d = null;
    foreach ($dt as $pref => $val) {
      if ($val != 0)
        $d .= $val . $pref . ' ';
    }

    $return = $d;

    return $return;
  }

  /**
   * Return singular or plural word
   *
   * NOT DOCUMENTED YET
   */
  public static function plural($singular, $plural, $count)
  {
    $return = sprintf(ngettext("%d $singular", "%d $plural", $count), $count);

    return $return;
  }

  /**
   * Return links to pages
   *
   * NOT DOCUMENTED YET
   */
  public static function paginationResult($resultsCount, $perPage, $currentPage)
  {
    $_GET['page'] = $currentPage;

    $pagesTotal = ceil($resultsCount / $perPage);

    if ($pagesTotal <= 1)
      return null;

    $query = $_GET;
    $query['page'] = $query['page'] - 1;
    $queryBuildPrev = $_SERVER['PHP_SELF'] . '?' . http_build_query($query);
    $query['page'] = $query['page'] + 2;
    $queryBuildNext = $_SERVER['PHP_SELF'] . '?' . http_build_query($query);

    $buttonPrev = (1 != $currentPage)
      ? '<li class="page-item"><a class="page-link" href="' . $queryBuildPrev . '"><i class="fas fa-lg fa-long-arrow-alt-left pe-2"></i>Previous</a></li>'
      : null;
    $buttonNext = ($pagesTotal != $currentPage)
      ? '<li class="page-item"><a class="page-link" href="' . $queryBuildNext . '">Next<i class="fas fa-lg fa-long-arrow-alt-right ms-2"></i></a></li>'
      : null;

    $htmlPages = null;
    $query = $_GET;
    for ($i = 1; $i <= $pagesTotal; $i++) {
      if (10 <= $pagesTotal && 1 != $i && $pagesTotal != $i && ($i < $currentPage - 1 || $i > $currentPage + 1)) {
        $skipPages = true;
        continue;
      }

      if (!empty($skipPages)) {
        $htmlPages .= '<li class="page-item"><a class="page-link text-body-tertiary bg-white disabled">...</a></li>';
        $skipPages = false;
      }

      if ($i != $currentPage) {
        $query['page'] = $i;
        $queryBuild = $_SERVER['PHP_SELF'] . '?' . http_build_query($query);

        $htmlPages .= sprintf('<li class="page-item"><a class="page-link" href="%2$s">%1$d</a></li>', $i, $queryBuild);
      } else
        $htmlPages .= sprintf('<li class="page-item active"><span class="page-link rounded-bottom-3 shadow fs-5 fw-bold">%1$d</span></li>', $i);
    }

    $params = [
      '%PAGES%' => $htmlPages,
      '%PAGE_PREV%' => $buttonPrev,
      '%PAGE_NEXT%' => $buttonNext
    ];
    $html = Functions::template('pagination', $params, 'search');

    return $html;
  }

  /**
   * Check availability of airline's logo
   *
   * NOT DOCUMENTED YET
   */
  public static function airlineLogo($iata)
  {
    $path = '/images/airlines/';
    $ext = '.png';

    $airline_logo = file_exists(__ROOT__ . $path . $iata . $ext) ? $path . $iata . $ext : $path . 'no-logo' . $ext;

    return $airline_logo;
  }

  /**
   * Calculating taxes
   *
   * NOT DOCUMENTED YET
   */
  public static function calculateTax($amount, $province = 'QC')
  {
    $tax = [
      /** Newfoundland and Labrador */
      'NL' => [
        'GST' => 0,
        'PST' => 0,
        'HST' => 15,
        'QST' => 0
      ],
      /** Prince Edward Island */
      'PE' => [
        'GST' => 0,
        'PST' => 0,
        'HST' => 15,
        'QST' => 0
      ],
      /** Nova Scotia */
      'NS' => [
        'GST' => 0,
        'PST' => 0,
        'HST' => 15,
        'QST' => 0
      ],
      /** New Brunswick */
      'NB' => [
        'GST' => 0,
        'PST' => 0,
        'HST' => 15,
        'QST' => 0
      ],
      /** Quebec */
      'QC' => [
        'GST' => 5,
        'PST' => 0,
        'HST' => 0,
        'QST' => 9.975
      ],
      /** Ontario */
      'ON' => [
        'GST' => 0,
        'PST' => 0,
        'HST' => 13,
        'QST' => 0
      ],
      /** Manitoba */
      'MB' => [
        'GST' => 5,
        'PST' => 7,
        'HST' => 0,
        'QST' => 0
      ],
      /** Saskatchewan */
      'SK' => [
        'GST' => 5,
        'PST' => 6,
        'HST' => 0,
        'QST' => 0
      ],
      /** Alberta */
      'AB' => [
        'GST' => 5,
        'PST' => 0,
        'HST' => 0,
        'QST' => 0
      ],
      /** British Columbia */
      'BC' => [
        'GST' => 5,
        'PST' => 7,
        'HST' => 0,
        'QST' => 0
      ],
      /** Yukon */
      'YT' => [
        'GST' => 5,
        'PST' => 0,
        'HST' => 0,
        'QST' => 0
      ],
      /** Northwest Territories */
      'NT' => [
        'GST' => 5,
        'PST' => 0,
        'HST' => 0,
        'QST' => 0
      ],
      /** Nunavut */
      'NU' => [
        'GST' => 5,
        'PST' => 0,
        'HST' => 0,
        'QST' => 0
      ],
    ];

    foreach ($tax[$province] as $abbr => $value) {
      if ($abbr === array_key_first($tax[$province]))
        $tax[$province]['taxes'] = 0; //$amount;

      $total_tax = $amount / 100 * $value;
      $total_tax = number_format(round($total_tax, 2), 2);
      $tax[$province][$abbr] = $total_tax;

      if ($abbr !== array_key_last($tax[$province]))
        $tax[$province]['taxes'] += $total_tax;
    }

    $tax[$province]['total'] = number_format(round($amount + $tax[$province]['taxes'], 2), 2);
    $tax[$province]['amount'] = number_format(round($amount, 2), 2);
    $tax[$province]['taxes'] = number_format(round($tax[$province]['taxes'], 2), 2);

    return $tax[$province];
  }

  /**
   * MySQL WHERE conditions for flights
   *
   * NOT DOCUMENTED YET
   */
  public static function SqlSearchWhere($tablePrefix = null, $returnFlight = false)
  {
    global $___set;
    global $db;
    global $search;
    global $sortBy;

    $tablePrefix = !empty($tablePrefix) ? $tablePrefix . '.' : null;

    $db->where($tablePrefix . 'departure_airport', $search['departure']);
    $db->where($tablePrefix . 'arrival_airport', $search['arrival']);
    $db->where($tablePrefix . 'departure_time', $search['date_departure'], 'LIKE');
    $db->where($tablePrefix . 'airline', $_SESSION['filterAirlines'], 'IN');
    /**/
  }

  /**
   * Generate timerange for range picker
   *
   * NOT DOCUMENTED YET
   */
  public static function generateTimeRange()
  {
    $time = '00:00';
    $range = [$time];
    //$time = date("H:i", strtotime('+30 minutes', $time)); die();
    while (true) {
      $time = date("H:i", strtotime('+30 minutes', strtotime($time)));

      if ('00:00' == $time)
        break;
      else
        $range[] = $time;
    }
    $range[] = '23:59';

    $range = "'" . implode("','", $range) . "'";

    return $range;
  }

  /**
   * Convert arrival time to airport timezone
   *
   * NOT DOCUMENTED YET
   */
  public static function timeZone($time, $timezone_departure, $timezone_arrival)
  {
    global $db;

    $arrival_time = self::$db->rawQuery('SELECT CONVERT_TZ(?, ?, ?) as arrival_local', [$time, $timezone_departure, $timezone_arrival]);
    $return = $arrival_time[0]['arrival_local'];

    return $return;
  }

  /**
   * Flights badges
   *
   * NOT DOCUMENTED YET
   */
  public static function flightBadges($flights)
  {
    $cheaper_price = 0; // round
    $shorter_duration = 0; // round
    $earlier_departure = 0;
    $earlier_arrival = 0;
    $higher_rating = 0;

    foreach ($flights as $id => $flight) {
      /** Cheaper price */
      $current_price =
        empty($flight['returning'])
        ? $flights[$id]['departure']['price']
        : $flights[$id]['departure']['price'] + $flights[$id]['returning']['price'];
      $cheaper_price_tmp =
        empty($flight['returning'])
        ? $flights[$cheaper_price]['departure']['price']
        : $flights[$cheaper_price]['departure']['price'] + $flights[$cheaper_price]['returning']['price'];
      if ($current_price < $cheaper_price_tmp)
        $cheaper_price = $id;

      /** Shorted flight duration */
      $current_duration =
        empty($flight['returning'])
        ? $flights[$id]['departure']['duration']
        : $flights[$id]['departure']['duration'] + $flights[$id]['returning']['duration'];
      $shorter_duration_tmp =
        empty($flight['returning'])
        ? $flights[$shorter_duration]['departure']['duration']
        : $flights[$shorter_duration]['departure']['duration'] + $flights[$shorter_duration]['returning']['duration'];
      if ($current_duration < $shorter_duration_tmp)
        $shorter_duration = $id;

      /** Earlier departure */
      if (strtotime($flights[$id]['departure']['departure_time']) < strtotime($flights[$earlier_departure]['departure']['departure_time']))
        $earlier_departure = $id;

      /** Earlier arrival */
      if (strtotime($flights[$id]['departure']['arrival_time']) < strtotime($flights[$earlier_arrival]['departure']['arrival_time']))
        $earlier_arrival = $id;

      /** Higher rating */
      $current_rating =
        empty($flight['returning'])
        ? $flights[$id]['departure']['rating']
        : ($flights[$id]['departure']['rating'] + $flights[$id]['returning']['rating']) / 2;
      if ($flights[$id]['departure']['rating'] > $flights[$higher_rating]['departure']['rating'])
        $higher_rating = $id;
    }

    $flights[$cheaper_price]['badge'][] = Config::$site['sort']['price']['badge'];
    $flights[$shorter_duration]['badge'][] = Config::$site['sort']['duration']['badge'];
    $flights[$earlier_departure]['badge'][] = Config::$site['sort']['departure_time']['badge'];
    $flights[$earlier_arrival]['badge'][] = Config::$site['sort']['arrival_time']['badge'];
    $flights[$higher_rating]['badge'][] = Config::$site['sort']['rating']['badge'];

    return $flights;
  }

  /**
   * Convert search hash to array
   *
   * NOT DOCUMENTED YET
   */
  public static function hash($hash)
  {
    $query_array['from'] = substr($hash, 0, 3);
    $query_array['departure_date'] = self::hash2date(substr($hash, 3, 6));
    $query_array['to'] = substr($hash, 9, 3);
    $query_array['returning_date'] = self::hash2date(substr($hash, 12, 6));

    return $query_array;
  }

  private static function hash2date($hash)
  {
    if (!empty($hash)) {
      $year = "20" . substr($hash, 0, 2);
      $month = substr($hash, 2, 2);
      $day = substr($hash, 4, 2);

      $timestamp = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));

      return $timestamp;
    } else
      return null;
  }

}
