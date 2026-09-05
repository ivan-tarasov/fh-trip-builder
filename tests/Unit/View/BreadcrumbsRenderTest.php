<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\TwigRenderer;

/**
 * The partial that draws the trail, rendered rather than reasoned about.
 *
 * BreadcrumbsTest covers the trail as data. Nothing covered the markup, which
 * is where the parts a visitor and a crawler actually meet live: the landmark,
 * the ordered list, which crumb is a link, and the JSON-LD beside it. A trail
 * can be perfectly correct and reach the page as nothing at all.
 */
final class BreadcrumbsRenderTest extends TestCase
{
    protected function setUp(): void
    {
        // The page map lives in config, and without it every trail is empty --
        // which the partial renders as nothing, so the assertions below would
        // all be about a blank string.
        new Config('common');
    }

    private function render(string $path, ?string $label = null): DOMXPath
    {
        $html = new TwigRenderer()->render('partials/breadcrumbs.html.twig', [
            'breadcrumbs' => Breadcrumbs::trail($path, $label),
        ]);

        $doc = new DOMDocument();
        // A fragment, so it is wrapped: libxml otherwise warns about the
        // missing document element and returns nothing useful.
        @$doc->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');

        return new DOMXPath($doc);
    }

    public function testTheTrailReachesThePageAsANavigationLandmark(): void
    {
        $xp = $this->render('/my/bookings/100001', 'K7PQ2M');

        self::assertSame(1, $xp->query('//nav[@aria-label="Breadcrumb"]')->length);
        self::assertSame(1, $xp->query('//nav//ol')->length, 'an ordered list, because a trail has an order');
        self::assertSame(
            ['Home', 'My bookings', 'K7PQ2M'],
            array_map(
                static fn(object $li): string => trim($li->textContent),
                iterator_to_array($xp->query('//nav//li')),
            ),
        );
    }

    public function testOnlyTheAncestorsAreLinks(): void
    {
        // The page you are on is not somewhere to go, and a link to it is a
        // control that does nothing.
        $xp = $this->render('/my/bookings/100001', 'K7PQ2M');

        self::assertSame(
            ['/', '/my/bookings'],
            array_map(
                static fn(object $a): string => $a->getAttribute('href'),
                iterator_to_array($xp->query('//nav//a')),
            ),
        );
        self::assertSame(1, $xp->query('//nav//li[@aria-current="page"]')->length);
        self::assertSame('K7PQ2M', trim($xp->query('//nav//li[@aria-current="page"]')->item(0)->textContent));
    }

    public function testAPageWithNoTrailRendersNothingAtAll(): void
    {
        // Not an empty <nav>: home and the booking funnel opt out, and an empty
        // landmark is still announced.
        foreach (['/', '/checkout', '/search'] as $path) {
            $xp = $this->render($path);

            self::assertSame(0, $xp->query('//nav')->length, $path);
            self::assertSame(0, $xp->query('//script')->length, $path);
        }
    }

    public function testTheStructuredDataIsRenderedBesideTheCrumbs(): void
    {
        $xp = $this->render('/airlines');
        $script = $xp->query('//script[@type="application/ld+json"]');

        self::assertSame(1, $script->length);

        $data = json_decode((string) $script->item(0)->textContent, true);

        self::assertSame('BreadcrumbList', $data['@type']);
        // The same names, in the same order, as the crumbs above it.
        self::assertSame(
            array_map(
                static fn(object $li): string => trim($li->textContent),
                iterator_to_array($xp->query('//nav//li')),
            ),
            array_column($data['itemListElement'], 'name'),
        );
    }
}
