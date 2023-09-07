<?php

namespace TripBuilder\Controllers;

use TripBuilder\AmazonS3;
use TripBuilder\Config;
use TripBuilder\DataBase\MySql;
use TripBuilder\Debug\dBug;
use TripBuilder\Helper;
use TripBuilder\Routs;
use TripBuilder\Templater;
use TripBuilder\Timer;

class AbstractController
{
    public $db;

    private string $staticUrl;

    public function __construct()
    {
        $this->dbConnect();

        $this->setStaticUrl(AmazonS3::getUrl());
    }

    /**
     * @return void
     */
    private function dbConnect(): void
    {
        $this->db = MySql::connect();

        $this->db->setTrace(true);
    }

    /**
     * Render header
     *
     * @return void
     * @throws \Exception
     */
    public function header(): void
    {
        $templater = new Templater('header', 'menu-item');

        foreach (Config::get('site.main-menu') as $link => $params) {
            if ($params['enabled']) {
                $templater
                    ->setPlaceholder('menu_item_url',        $link)
                    ->setPlaceholder('menu_item_spacer',     $params['spacer'] ?? 2)
                    ->setPlaceholder('menu_item_active', Routs::getCurrentPage() == rtrim($link, '/') ? ' active' : null)
                    ->setPlaceholder('menu_item_icon',       $params['icon'])
                    ->setPlaceholder('menu_item_text',       $params['text'])
                    ->save();
            }
        }

        $html_mainMenu = $templater->render();

        $html_counters = $templater->setFilename('counters')->set()->save()->render();

        echo $templater
            ->setPath('header')
            ->setFilename('view')
            ->set()
            ->setPlaceholder('app_name',              Config::get('app.name'))
            ->setPlaceholder('page_title',            'Main Page')
            ->setPlaceholder('app_meta_description',  Config::get('meta.description'))
            ->setPlaceholder('app_meta_keywords',     implode(', ', Config::get('meta.keywords')))
            ->setPlaceholder('app_meta_author_name',  Config::get('meta.author.name'))
            ->setPlaceholder('app_meta_author_email', Config::get('meta.author.email'))
            ->setPlaceholder('app_vendor_folder',     sprintf('%s/%s', $this->staticUrl, Config::get('site.static.endpoint.vendor')))
            ->setPlaceholder('app_css_folder',        Config::get('site.directory.css'))
            ->setPlaceholder('menu_items',            $html_mainMenu)
            ->setPlaceholder('user_avatar',           Config::get('site.avatar'))
            ->setPlaceholder('metric_counters',       $html_counters)
            ->save()
            ->render();
    }

    /**
     * Render footer
     *
     * @return void
     * @throws \Exception
     */
    public function footer(): void
    {
        $templater = new Templater('footer', 'main-menu-item');

        // Building main menu
        foreach (Config::get('site.main-menu') as $url => $params) {
            $templater
                ->setPlaceholder('menu_item_url', $url)
                ->setPlaceholder('menu_item_title', $params['text'])
                ->save();
        }
        $html_mainMenu = $templater->render();

        // Building social links menu
        foreach (Config::get('site.footer-social') as $title => $params) {
            $templater
                ->setFilename('social-menu-item')
                ->set()
                ->setPlaceholder('menu_item_title', $title)
                ->setPlaceholder('menu_item_url', $params['url'])
                ->setPlaceholder('menu_item_icon', $params['ico'])
                ->save();
        }
        $html_socialMenu = $templater->render();

        // Building git menu
        foreach (Config::get('site.footer-git') as $title => $url) {
            $templater
                ->setFilename('git-menu-item')
                ->set()
                ->setPlaceholder('menu_item_title', $title)
                ->setPlaceholder('menu_item_url', $url)
                ->save();
        }
        $html_gitMenu = $templater->render();

            // Building application version string
        $gitInfo = Helper::getGitInfo();

        $html_appVersion = $templater
            ->setFilename('app-version')
            ->set()
            ->setPlaceholder('item_url', sprintf('%s/commit/%s', Helper::getGitRepo(), $gitInfo['commit_hash']))
            ->setPlaceholder('item_title', sprintf(
                '%s-%s-%s',
                $gitInfo['tag'],
                $gitInfo['branch'],
                $gitInfo['commit_hash']
            ))
            ->setPlaceholder('item_posted_at', 'posted at ' . $gitInfo['commit_date'])
            ->save()
            ->render();

        echo $templater
            ->setPath('footer')
            ->setFilename('view')
            ->set()
            ->setPlaceholder('app_name',              Config::get('app.name'))
            ->setPlaceholder('app_author_name',       Config::get('meta.author.name'))
            ->setPlaceholder('app_author_website',    Config::get('meta.author.website'))
            ->setPlaceholder('app_license_type',      Config::get('app.license.type'))
            ->setPlaceholder('app_license_url',       Config::get('app.license.url'))
            ->setPlaceholder('app_documentation_url', Config::get('app.documentation'))
            ->setPlaceholder('copyright_years',       $this->copyrightYears())
            ->setPlaceholder('app_version',           $html_appVersion)
            ->setPlaceholder('main_menu',             $html_mainMenu)
            ->setPlaceholder('social_menu',           $html_socialMenu)
            ->setPlaceholder('git_menu',              $html_gitMenu)
            ->setPlaceholder('flights_count',         number_format($this->getFlightsCount()))
            ->setPlaceholder('database_requests',     $this->getDbRequestCount())
            ->setPlaceholder('execution_time',        $this->getExecutionTime())
            ->setPlaceholder('app_vendor_folder',     sprintf('%s/%s', $this->staticUrl, Config::get('site.static.endpoint.vendor')))
            ->setPlaceholder('app_js_folder',         Config::get('site.directory.js'))
            ->save()
            ->render();
    }

    /**
     * @return int|string
     */
    private function copyrightYears(): int|string
    {
        $appYear     = Config::get('app.year');
        $currentYear = date('Y');

        return $appYear == $currentYear
            ? $currentYear
            : $appYear . '–' . $currentYear;
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getFlightsCount(): string
    {
        $count = $this->db->getOne('flights', 'count(*) as flights');

        return $count['flights'];
    }

    /**
     * @return int
     */
    private function getDbRequestCount(): int
    {
        return count($this->db->trace);
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getExecutionTime(): string
    {
        Timer::stop();

        return Timer::getExecutionTime();
    }

    private function setStaticUrl($url): void
    {
        $this->staticUrl = $url;
    }

}
