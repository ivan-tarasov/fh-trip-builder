<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use DOMDocument;
use DOMElement;
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

    /**
     * The matching elements, as a plain list.
     *
     * `query()` answers false for an expression libxml will not parse, which
     * is a broken test rather than a failed assertion — and reading `->length`
     * off false is a fatal that names neither. A list rather than the node
     * list, because every caller here counts or maps over it.
     *
     * @return list<DOMElement>
     */
    private function nodes(DOMXPath $xp, string $expression): array
    {
        $found = $xp->query($expression);

        self::assertNotFalse($found, $expression . ' is not valid XPath');

        $elements = [];

        foreach ($found as $node) {
            // A namespace node is the one thing an XPath query can return that
            // is not an element, and nothing here asks for one.
            self::assertInstanceOf(DOMElement::class, $node, $expression . ' matched a non-element');
            $elements[] = $node;
        }

        return $elements;
    }

    /** The first match, which the caller is saying it expects to be there. */
    private function node(DOMXPath $xp, string $expression): DOMElement
    {
        $nodes = $this->nodes($xp, $expression);

        self::assertNotSame([], $nodes, 'nothing matches ' . $expression);

        return $nodes[0];
    }

    public function testTheTrailReachesThePageAsANavigationLandmark(): void
    {
        $xp = $this->render('/my/bookings/100001', 'K7PQ2M');

        self::assertSame(1, count($this->nodes($xp, '//nav[@aria-label="Breadcrumb"]')));
        self::assertSame(1, count($this->nodes($xp, '//nav//ol')), 'an ordered list, because a trail has an order');
        self::assertSame(
            ['Home', 'My bookings', 'K7PQ2M'],
            array_map(
                static fn(DOMElement $li): string => trim($li->textContent),
                $this->nodes($xp, '//nav//li'),
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
                static fn(DOMElement $a): string => $a->getAttribute('href'),
                $this->nodes($xp, '//nav//a'),
            ),
        );
        self::assertSame(1, count($this->nodes($xp, '//nav//li[@aria-current="page"]')));
        self::assertSame('K7PQ2M', trim($this->node($xp, '//nav//li[@aria-current="page"]')->textContent));
    }

    public function testAPageWithNoTrailRendersNothingAtAll(): void
    {
        // Not an empty <nav>: home and the booking funnel opt out, and an empty
        // landmark is still announced.
        foreach (['/', '/checkout', '/search'] as $path) {
            $xp = $this->render($path);

            self::assertSame(0, count($this->nodes($xp, '//nav')), $path);
            self::assertSame(0, count($this->nodes($xp, '//script')), $path);
        }
    }

    /**
     * Neither trail draws its separator in a rule colour.
     *
     * This has been wrong twice, on the two halves of the same trail. The
     * on-dark separators were `--line-on-dark` at 1.44:1 on the band, fixed in
     * PR #76; the light ones stayed `--line-strongest` at 1.46:1 on
     * `--surface` for two releases after, because nothing connected the two
     * and the second half is not visible from the first.
     *
     * The rule tier is mixed for hairlines between panels. A chevron drawn in
     * it is a mark carrying meaning that nobody can see -- the same call the
     * datepicker's today marker already makes, in the same words.
     *
     * Asserted against the stylesheet rather than the render, the way
     * FooterRenderTest asserts its focus rings: what a render can show is the
     * markup, and what broke here was the colour.
     */
    public function testNeitherTrailDrawsItsSeparatorInARuleColour(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/main.css');

        foreach ([
            'the light trail' => '/\.breadcrumbs__item \+ \.breadcrumbs__item::before \{(.*?)\}/s',
            'the trail in a band' => '/\.breadcrumbs--on-dark \.breadcrumbs__item \+ [^{]*\{(.*?)\}/s',
        ] as $which => $pattern) {
            self::assertSame(1, preg_match($pattern, $css, $rule), $which . ' has no separator rule to read');

            self::assertMatchesRegularExpression(
                '/color: var\(--ink-[a-z-]+\)/',
                $rule[1],
                $which . ' should colour its separator from the ink scale',
            );

            self::assertDoesNotMatchRegularExpression(
                '/color: var\(--line[a-z-]*\)/',
                $rule[1],
                $which . ' draws a glyph in a colour mixed for hairlines',
            );
        }
    }

    public function testTheStructuredDataIsRenderedBesideTheCrumbs(): void
    {
        $xp = $this->render('/airlines');
        $script = $this->nodes($xp, '//script[@type="application/ld+json"]');

        self::assertSame(1, count($script));

        $data = json_decode((string) $script[0]->textContent, true);

        self::assertSame('BreadcrumbList', $data['@type']);
        // The same names, in the same order, as the crumbs above it.
        self::assertSame(
            array_map(
                static fn(DOMElement $li): string => trim($li->textContent),
                $this->nodes($xp, '//nav//li'),
            ),
            array_column($data['itemListElement'], 'name'),
        );
    }
}
