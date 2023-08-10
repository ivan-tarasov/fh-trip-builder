<?php

namespace TripBuilder\Controllers;

use TripBuilder\Config;
use TripBuilder\Debug\dBug;
use TripBuilder\Helper;
use TripBuilder\Routs;
use TripBuilder\Templater;

class AbstractController
{
    /**
     * Render header
     *
     * @return void
     * @throws \Exception
     */
    public function header(): void
    {
        $html_mainMenu = '';

        $templater = new Templater('header', 'menu-item');

        foreach (Config::get('site', 'main-menu') as $link => $params) {
            $html_mainMenu .= $templater
                ->setPlaceholder('MENU_ITEM_LINK', $link)
                ->setPlaceholder('CURRENT_PAGE', Routs::getCurrentPage() == $link ? 'white' : 'secondary')
                ->setPlaceholder('MENU_ITEM_TEXT', $params['text'])
                ->setPlaceholder('MENU_ITEM_ICON', $params['icon'])
                ->setPlaceholder('MENU_ITEM_SPACER', $params['spacer'] ?? 2)
                ->render();
        }

        $html_counters = $templater->setFilename('counters')->set()->render();

        echo $templater->setPath('header')->setFilename('view')->set()
            ->setPlaceholder('PAGE_TITLE', 'Main Page')
            ->setPlaceholder('HEADER_MENU_ITEMS', $html_mainMenu)
            ->setPlaceholder('METRIKA_COUNTERS', $html_counters)
            ->render();
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
        $html_mainMenu = '';

        foreach (Config::get('site', 'main-menu') as $url => $params) {
            $html_mainMenu .= $templater
                ->setPlaceholder('menu-item-url', $url)
                ->setPlaceholder('menu-item-title', $params['text'])
                ->render();
        }

        // Building social links menu
        $html_socialMenu = '';

        foreach (Config::get('site', 'footer-social') as $title => $params) {
            $html_socialMenu .= $templater
                ->setFilename('social-menu-item')->set()
                ->setPlaceholder('menu-item-title', $title)
                ->setPlaceholder('menu-item-url', $params['url'])
                ->setPlaceholder('menu-item-icon', $params['ico'])
                ->render();
        }

        // Building social links menu
        $html_gitMenu = '';

        foreach (Config::get('site', 'footer-git') as $title => $url) {
            $html_gitMenu .= $templater
                ->setFilename('git-menu-item')->set()
                ->setPlaceholder('menu-item-title', $title)
                ->setPlaceholder('menu-item-url', $url)
                ->render();
        }

        // Building application version string
        $gitInfo = Helper::getGitInfo();

        $html_appVersion = $templater
            ->setFilename('app-version')->set()
            ->setPlaceholder('item-url', Config::get('site', 'git') . $gitInfo['commit_hash'])
            ->setPlaceholder('item-title', sprintf(
                '%s.%s.%s-%s-%s',
                Config::get('site', 'version.major'),
                Config::get('site', 'version.minor'),
                Config::get('site', 'version.patch'),
                $gitInfo['branch'],
                $gitInfo['commit_hash']
            ))
            ->setPlaceholder('item-posted-at', 'posted at ' . $gitInfo['commit_date'])
            ->render();

        echo $templater->setPath('footer')->setFilename('view')->set()
            ->setPlaceholder('APP_VERSION', $html_appVersion)
            ->setPlaceholder('EXECUTION_TIMER', 0)   // FIXME: here should be real timer
            ->setPlaceholder('DATABASE_REQUESTS', 0) // FIXME: here should be real DB requests count
            ->setPlaceholder('FLIGHTS_COUNT', 0)     // FIXME: here should be real flight count from DB
            ->setPlaceholder('API_CALLS_COUNT', 0)   // FIXME: DO WE NEED IT ???
            ->setPlaceholder('FOOTER_MENU_MAIN', $html_mainMenu)
            ->setPlaceholder('FOOTER_MENU_SOCIAL', $html_socialMenu)
            ->setPlaceholder('FOOTER_MENU_GIT', $html_gitMenu)
            ->render();
    }

}
