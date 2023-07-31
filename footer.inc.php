<?php
/**
 * Footer
 *
 * @author    Ivan Tarasov <ivan@tarasov.ca>
 * @copyright Copyright (c) 2023
 * @version   0.1.4
 */

$db_flights_count = number_format(round(Functions::$db->getOne('flights', 'count(*) as cnt')['cnt'], 2));

$html_menu_main   =
$html_menu_social =
$html_menu_git    = '';

foreach (Config::$site['main-menu'] as $link => $params) {
    $html_menu_main .= sprintf(
        '<li class="mb-2"><a href="/%s.php" class="link-light">%s</a></li>',
        $link,
        $params['text']
    );
}

foreach (Config::$site['footer-social'] as $title => $params) {
    $html_menu_social .= sprintf(
        '<li class="mb-2"><a href="%s" class="link-light" target="_blank"><i class="fa-brands fa-lg fa-%s pe-2"></i>%s</a></li>',
        $params['url'],
        $params['ico'],
        $title
    );
}

foreach (Config::$site['footer-git'] as $title => $url) {
    $html_menu_git .= sprintf(
        '<li class="mb-2"><a href="%s" class="link-light" target="_blank">%s</a></li>',
        $url,
        $title
  );
}

$app_version = sprintf(
    '<span class="font-monospace small"><a href="%4$s%6$s" class="text-light" target="_blank">%1$s.%2$s.%3$s-%5$s.%6$s</a> posted at %7$s</span>',
    Config::$site['version']['major'],
    Config::$site['version']['minor'],
    Config::$site['version']['patch'],
    Config::$site['git'],
    Functions::gitInfo()['branch'],
    Functions::gitInfo()['commit_hash'],
    Functions::gitInfo()['commit_date']
);

$footer_params = [
    '%APP_VERSION%'        => $app_version,
    '%EXECUTION_TIMER%'    => Timer::finish(),
    '%DATABASE_REQUESTS%'  => count(Functions::$db->trace),
    '%FLIGHTS_COUNT%'      => $db_flights_count,
    '%API_CALLS_COUNT%'    => $_SESSION['api_call_count'] ?? 0,
    '%FOOTER_MENU_MAIN%'   => $html_menu_main,
    '%FOOTER_MENU_SOCIAL%' => $html_menu_social,
    '%FOOTER_MENU_GIT%'    => $html_menu_git
];

$html .= Functions::template('footer', $footer_params, 'index');

unset($_SESSION['api_call_count']);

echo $html;
