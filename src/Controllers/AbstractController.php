<?php

namespace TripBuilder\Controllers;

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

    public function __construct()
    {
        $this->dbConnect();
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

        foreach (Config::get('site', 'main-menu') as $link => $params) {
            $templater
                ->setPlaceholder('MENU_ITEM_LINK', $link)
                ->setPlaceholder('CURRENT_PAGE', Routs::getCurrentPage() == $link ? 'white' : 'secondary')
                ->setPlaceholder('MENU_ITEM_TEXT', $params['text'])
                ->setPlaceholder('MENU_ITEM_ICON', $params['icon'])
                ->setPlaceholder('MENU_ITEM_SPACER', $params['spacer'] ?? 2)
                ->save();
        }

        $html_mainMenu = $templater->render();

        $html_counters = $templater->setFilename('counters')->set()->save()->render();

        echo $templater->setPath('header')->setFilename('view')->set()
            ->setPlaceholder('app-name', Config::get('app', 'name'))
            ->setPlaceholder('page-title', 'Main Page')
            ->setPlaceholder('app-meta-description', Config::get('app', 'meta.description'))
            ->setPlaceholder('app-meta-keywords', implode(', ', Config::get('app', 'meta.keywords')))
            ->setPlaceholder('app-meta-author-name', Config::get('app', 'author.name'))
            ->setPlaceholder('app-meta-author-email', Config::get('app', 'author.email'))
            ->setPlaceholder('menu-items', $html_mainMenu)
            ->setPlaceholder('metric-counters', $html_counters)
            ->save()->render();
    }

    /**
     * Render footer
     *
     * @return void
     * @throws \Exception
     */
    public function footer()
    {
        $templater = new Templater('footer', 'main-menu-item');

        // Building main menu
        foreach (Config::get('site', 'main-menu') as $url => $params) {
            $templater
                ->setPlaceholder('menu-item-url', $url)
                ->setPlaceholder('menu-item-title', $params['text'])
                ->save();
        }
        $html_mainMenu = $templater->render();

        // Building social links menu
        foreach (Config::get('site', 'footer-social') as $title => $params) {
            $templater
                ->setFilename('social-menu-item')->set()
                ->setPlaceholder('menu-item-title', $title)
                ->setPlaceholder('menu-item-url', $params['url'])
                ->setPlaceholder('menu-item-icon', $params['ico'])
                ->save();
        }
        $html_socialMenu = $templater->render();

        // Building social links menu
        foreach (Config::get('site', 'footer-git') as $title => $url) {
            $templater
                ->setFilename('git-menu-item')->set()
                ->setPlaceholder('menu-item-title', $title)
                ->setPlaceholder('menu-item-url', $url)
                ->save();
        }
        $html_gitMenu = $templater->render();

            // Building application version string
        $gitInfo = Helper::getGitInfo();

        $html_appVersion = $templater
            ->setFilename('app-version')->set()
            ->setPlaceholder('item-url', sprintf('%s/commit/%s', Helper::getGitRepo(), $gitInfo['commit_hash']))
            ->setPlaceholder('item-title', sprintf(
                '%s.%s.%s-%s-%s',
                Config::get('app', 'version.major'),
                Config::get('app', 'version.minor'),
                Config::get('app', 'version.patch'),
                $gitInfo['branch'],
                $gitInfo['commit_hash']
            ))
            ->setPlaceholder('item-posted-at', 'posted at ' . $gitInfo['commit_date'])
            ->save()->render();

        echo $templater->setPath('footer')->setFilename('view')->set()
            ->setPlaceholder('app-name', Config::get('app', 'name'))
            ->setPlaceholder('app-author-name', Config::get('app', 'author.name'))
            ->setPlaceholder('app-author-website', Config::get('app', 'author.website'))
            ->setPlaceholder('app-license-type', Config::get('app', 'license.type'))
            ->setPlaceholder('app-license-url', Config::get('app', 'license.url'))
            ->setPlaceholder('app-documentation-url', Config::get('app', 'documentation'))
            ->setPlaceholder('copyright-years', $this->copyrightYears())
            ->setPlaceholder('app-version', $html_appVersion)
            ->setPlaceholder('main-menu', $html_mainMenu)
            ->setPlaceholder('social-menu', $html_socialMenu)
            ->setPlaceholder('git-menu', $html_gitMenu)
            ->setPlaceholder('flights-count', number_format($this->getFlightsCount()))
            ->setPlaceholder('database-requests', $this->getDbRequestCount())
            ->setPlaceholder('execution-time', $this->getExecutionTime())
            ->save()->render();
    }

    private function copyrightYears()
    {
        $appYear     = Config::get('app', 'year');
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

}
