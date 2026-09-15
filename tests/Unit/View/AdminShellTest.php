<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * **Bootstrap came back in A10.1 (#287)**, deliberately and scoped to the
 * panel -- what this file still guards is the shape of the decision, not the
 * exact count: nothing loaded from a CDN, no jQuery, no Font Awesome, no
 * accidental fourth or fifth tag nobody meant to add.
 *
 * That is one `{% extends %}` away from bringing all of it back, and nothing
 * else here would notice. So this reads the templates.
 */
final class AdminShellTest extends TestCase
{
    private const string LAYOUT = 'templates/admin/layout.html.twig';

    /** Every page of the panel. */
    private const array PAGES = [
        'templates/admin/overview.html.twig',
        'templates/admin/content.html.twig',
        'templates/admin/bookings.html.twig',
        'templates/admin/booking.html.twig',
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
     * The panel loads its own files, one vendored pair, and nothing else.
     *
     * Not a style rule. Every one of these is a request an operator pays for
     * on a page that cannot use it, and the list only ever grows by accident.
     *
     * **Bootstrap is back as of A10.1 (#287)**, on purpose and scoped to the
     * panel -- what stays banned is a CDN. Bootstrap and Bootstrap Icons are
     * vendored into `public/` from the official npm packages, the same way
     * the typeface already is, so this still fetches nothing from off this
     * server. jQuery, Font Awesome, sweetalert, a range slider and Mapbox stay
     * out: nothing here uses them, and Bootstrap 5 needs none of them.
     */
    public function testThePanelLoadsNothingItDoesNotOwn(): void
    {
        // Without the comments: this file names every one of them, in the
        // paragraph explaining what changed and why.
        $layout = self::stripComments(self::read(self::LAYOUT));

        self::assertStringContainsString("/bootstrap.min.css'", $layout);
        self::assertStringContainsString("/admin.css'", $layout);
        self::assertStringContainsString("/bootstrap.bundle.min.js'", $layout);
        self::assertStringContainsString("/admin.js'", $layout);

        // Nothing is fetched from anywhere but this server. Every CDN link in
        // this codebase is written protocol-relative, so one `//` outside a
        // comment is one asset too many. The typeface is the site's and is
        // self-hosted, so it arrives through `admin.css` rather than as a tag.
        self::assertStringNotContainsString('//', $layout, 'the panel is fetching something from off this server');

        foreach (['jquery', 'fontawesome', 'font-awesome', 'sweetalert', 'rangeslider', 'mapbox', 'cdnjs', 'jsdelivr'] as $stranger) {
            self::assertStringNotContainsString(
                $stranger,
                strtolower($layout),
                $stranger . ' is back in the panel',
            );
        }

        // Four, and no more: a vendored pair plus our own pair.
        self::assertSame(2, substr_count($layout, 'rel="stylesheet"'));
        self::assertSame(2, substr_count($layout, '<script src='));
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

    /**
     * The panel wears the site's palette, and keeps wearing it.
     *
     * The values are copied into `admin.css` rather than inherited, because
     * inheriting would mean loading all 8,500 lines of `main.css` to get forty
     * tokens. A copy drifts, so this is what stops it: change a colour on the
     * public side and this fails until the panel is changed with it.
     *
     * The rail is the deliberate exception and is not listed. It wears the
     * site's navy, but its accent has to be the *lifted* teal in both palettes
     * -- measured, the light one is 2.85 on that navy, which is under the bar
     * for text.
     */
    #[DataProvider('sharedColours')]
    public function testThePanelWearsTheSitesPalette(string $admin, string $site): void
    {
        self::assertSame(
            self::colour(self::read('public/css/main.css'), $site),
            self::colour(self::read('public/css/admin.css'), $admin),
            $admin . ' has drifted from main.css ' . $site,
        );
    }

    /**
     * Admin token => the one in `main.css` it was copied from.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function sharedColours(): iterable
    {
        $light = [
            '--ground' => '--surface',
            '--surface' => '--surface-raised',
            '--sunken' => '--surface-sunken',
            '--rule' => '--line',
            '--rule-strong' => '--line-strong',
            '--ink' => '--ink-body',
            '--ink-quiet' => '--ink-muted',
            '--ink-faint' => '--ink-faint',
            '--signal' => '--brand-accent',
            '--signal-soft' => '--brand-tint',
            '--signal-edge' => '--brand-tint-edge',
            '--good' => '--brand-success',
            '--good-soft' => '--success-tint',
            '--good-edge' => '--success-tint-edge',
            '--bad' => '--brand-danger',
            '--bad-soft' => '--danger-tint',
            '--bad-edge' => '--danger-tint-edge',
            '--rail' => '--brand-ink',
            '--rail-rule' => '--brand-ink-700',
            // The rail's accent is the dark-palette teal in both palettes,
            // because the rail is a dark band in both.
            '--rail-signal' => '--d-brand-accent',
        ];

        foreach ($light as $admin => $site) {
            yield $admin . ' is ' . $site => [$admin, $site];
        }
    }

    /**
     * A token's first declared colour.
     *
     * First, because both files declare each one twice -- once for the light
     * palette and once for dark -- and the light block comes first in each.
     */
    private static function colour(string $css, string $token): string
    {
        $found = preg_match('/' . preg_quote($token, '/') . ':\s*(#[0-9A-Fa-f]{3,8})\s*;/', $css, $match);

        self::assertSame(1, $found, $token . ' is not declared as a colour');

        return strtoupper($match[1]);
    }

    /**
     * The booking list carries a name and nothing else about a person.
     *
     * This pinned *no names at all* when it was written. It was changed on
     * purpose: finding the right booking is usually being done by name, because
     * somebody rings up and says who they are and nobody rings up and quotes a
     * database id. A list that cannot be scanned for the name on the phone is
     * one an operator opens ten bookings from, and ten pages of contact details
     * is worse than one column of names (A3.8, #233).
     *
     * What did not change is everything else. `bookings` also holds an address,
     * a phone number, a date of birth and four digits of a card -- PIPEDA scope,
     * which is why `db:prune` sweeps it at all -- and those stay on the page for
     * the one booking an operator opened, where looking was a deliberate act.
     *
     * Worth a test because the cheapest way to make a list more useful is to add
     * a column, and every column left is one of those four.
     */
    public function testTheBookingListShowsNoContactDetails(): void
    {
        $list = self::stripComments(self::read('templates/admin/bookings.html.twig'));

        foreach (['contact_email', 'contact_phone', 'dob', 'card_last4', 'session'] as $personal) {
            self::assertStringNotContainsString(
                $personal,
                $list,
                $personal . ' is on the list, where finding a booking does not need it',
            );
        }
    }

    /**
     * Every form in the panel that changes something carries its token.
     *
     * The panel's POSTs move an article, cancel a booking and sign an operator
     * out, and a `Csrf::isValid()` in the controller is only half of that pair:
     * the other half is a hidden field somebody has to remember to put in the
     * template. Counted rather than parsed, because a form with no token and a
     * token with no form are both the same mistake.
     */
    public function testEveryFormThatChangesSomethingCarriesItsToken(): void
    {
        foreach ([...self::PAGES, self::LAYOUT] as $page) {
            $template = self::stripComments(self::read($page));

            self::assertSame(
                preg_match_all('/<form[^>]*method="post"/i', $template),
                substr_count($template, 'csrf_field()'),
                $page . ' has a POST form and a token, and not the same number of each',
            );
        }
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
