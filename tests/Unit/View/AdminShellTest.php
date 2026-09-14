<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * The panel is its own document, and stays its own document.
 *
 * Measured before this existed: the sign-in page was 48KB of HTML and
 * eighteen link/script tags -- jQuery, Bootstrap, Font Awesome, sweetalert, a
 * range slider, a datepicker, a map stylesheet -- for one password field, all
 * of it inherited from a layout built to sell flights. Afterwards: 1.5KB and
 * two tags, both its own (A3.5, #230).
 *
 * That is one `{% extends %}` away from coming back, and nothing else here
 * would notice. So this reads the templates.
 */
final class AdminShellTest extends TestCase
{
    private const string LAYOUT = 'templates/admin/layout.html.twig';

    /** Every page of the panel. */
    private const array PAGES = [
        'templates/admin/overview.html.twig',
        'templates/admin/content.html.twig',
        'templates/admin/article.html.twig',
        'templates/admin/category.html.twig',
        'templates/admin/login.html.twig',
    ];

    public function testEveryAdminPageExtendsThePanelsOwnLayout(): void
    {
        foreach (self::PAGES as $page) {
            self::assertStringContainsString(
                "{% extends 'admin/layout.html.twig' %}",
                self::read($page),
                $page . ' is drawn inside the public layout',
            );
        }
    }

    /**
     * The panel loads its own two files and nothing else.
     *
     * Not a style rule. Every one of these is a request an operator pays for
     * on a page that cannot use it, and the list only ever grows by accident.
     */
    public function testThePanelLoadsNothingItDoesNotOwn(): void
    {
        // Without the comments: this file names every one of them, in the
        // paragraph explaining that they are gone.
        $layout = self::stripComments(self::read(self::LAYOUT));

        self::assertStringContainsString("/admin.css'", $layout);
        self::assertStringContainsString("/admin.js'", $layout);

        foreach (['jquery', 'bootstrap', 'fontawesome', 'font-awesome', 'sweetalert', 'rangeslider', 'mapbox', 'cdnjs', 'jsdelivr'] as $stranger) {
            self::assertStringNotContainsString(
                $stranger,
                strtolower($layout),
                $stranger . ' is back in the panel',
            );
        }

        // Two, and no more: the count is the assertion.
        self::assertSame(1, substr_count($layout, 'rel="stylesheet"'));
        self::assertSame(1, substr_count($layout, '<script src='));
    }

    /**
     * It has to open with a doctype.
     *
     * `Kernel::wrapped()` puts anything that does not into `layout.html.twig`,
     * so a panel page missing this would be served wrapped in the whole site.
     */
    public function testTheLayoutOpensWithADoctype(): void
    {
        self::assertStringStartsWith('<!DOCTYPE html>', ltrim(self::stripComments(self::read(self::LAYOUT))));
    }

    /**
     * Nobody should ever arrive here from a search engine.
     *
     * `PRIVATE_PREFIXES` already keeps `/admin` out of the sitemap and
     * `robots.txt`; this is the half that travels with the page.
     */
    public function testThePanelAsksNotToBeIndexed(): void
    {
        self::assertMatchesRegularExpression(
            '/<meta name="robots" content="noindex[^"]*">/',
            self::stripComments(self::read(self::LAYOUT)),
        );
    }

    /**
     * The sign-in page draws no rail.
     *
     * There is nothing to navigate until somebody is signed in, and the shape
     * of the panel is not a stranger's to read.
     */
    public function testTheWayInShowsNothingOfTheInside(): void
    {
        $login = self::stripComments(self::read('templates/admin/login.html.twig'));

        self::assertStringContainsString('{% block shell %}', $login, 'the gate must replace the shell, not sit inside it');
        self::assertStringNotContainsString('admin/partials/rail.html.twig', $login);
        self::assertStringNotContainsString('class="rail', $login);
    }

    /**
     * The stylesheet answers in both palettes.
     *
     * Three blocks and not one: bare `:root` for light, the media query for a
     * machine that dims itself, and `[data-theme="dark"]` so a reader who chose
     * dark on the public side gets dark here too. A colour defined only inside
     * the media query would be missing for anyone who had chosen.
     */
    public function testTheStylesheetCarriesBothPalettes(): void
    {
        $css = self::read('public/css/admin.css');

        self::assertStringContainsString(':root {', $css);
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertStringContainsString(':root[data-theme="dark"]', $css);

        // The ground has to be painted, or the panel borrows whatever is behind
        // it -- which on a dark machine is a dark page with dark text.
        self::assertMatchesRegularExpression('/body \{[^}]*background: var\(--ground\)/', $css);
    }

    /**
     * The theme is read before the first paint, for the reason the public
     * header gives: a script at the foot runs after it, so a dark reader would
     * see the light palette flash on every navigation.
     */
    public function testTheThemeIsSettledBeforeAnythingIsPainted(): void
    {
        $layout = self::stripComments(self::read(self::LAYOUT));
        $script = strpos($layout, "getItem('tb-theme')");
        $sheet = strpos($layout, 'rel="stylesheet"');

        self::assertIsInt($script, 'nothing reads the stored theme');
        self::assertIsInt($sheet);
        self::assertLessThan($sheet, $script, 'the theme is read after the stylesheet, so it cannot beat the paint');
    }

    private static function read(string $path): string
    {
        $file = Helper::getRootDir() . '/' . $path;

        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /** Twig comments, which may legitimately precede the doctype. */
    private static function stripComments(string $template): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $template);
    }
}
