<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\GreatCircle;
use TripBuilder\Helper;
use TripBuilder\View\TwigRenderer;

/**
 * The route map: two pins, a flight path, and a legend saying which pin is
 * which.
 *
 * The pins carry their cities' names and a colour each. They used to read "1"
 * and "2", which named neither place: the flat picture's endpoint takes a
 * single character on a pin and still cannot do better, but the live map has no
 * such limit and labels them properly.
 *
 * The legend stays, and not as a duplicate. The live map draws its labels into
 * a canvas that a screen reader cannot read, and the picture behind
 * `<noscript>` has unlabelled pins -- so the legend is the text those two cases
 * have, and the colour key for everyone else. It also holds the one coupling
 * that spans two files: the dot colours in the stylesheet stand for the pin
 * colours the map is given, and nothing but agreement makes them mean
 * anything.
 */
final class RouteMapRenderTest extends TestCase
{
    /** Sampled from a rendered pin; see the note in main.css. */
    private const string ORIGIN_PIN = '#0EB600';
    private const string DESTINATION_PIN = '#EC3735';

    private const string DUMMY_TOKEN = 'pk.dummy-token-for-tests';

    private string|false $realToken = false;

    protected function setUp(): void
    {
        new Config('common');

        // A map needs a token or it is not drawn at all, so this test supplies
        // one and puts the real one back -- see MapViewTest.
        $this->realToken = getenv('MAPBOX_TOKEN');
        putenv('MAPBOX_TOKEN=' . self::DUMMY_TOKEN);
        $_ENV['MAPBOX_TOKEN'] = self::DUMMY_TOKEN;
    }

    protected function tearDown(): void
    {
        if (is_string($this->realToken)) {
            putenv('MAPBOX_TOKEN=' . $this->realToken);
            $_ENV['MAPBOX_TOKEN'] = $this->realToken;

            return;
        }

        putenv('MAPBOX_TOKEN');
        unset($_ENV['MAPBOX_TOKEN']);
    }

    private function render(): string
    {
        return new TwigRenderer()->render('route/blocks/facts.html.twig', [
            'place' => [
                'cheapest' => 471.76,
                'typical' => 411,
                'km' => 5536,
                'carriers' => 7,
                'from' => ['name' => 'New York', 'latitude' => 40.7019, 'longitude' => -73.9462],
                'to' => ['name' => 'London', 'latitude' => 51.5032, 'longitude' => -0.1228],
                'path' => GreatCircle::segments(40.7019, -73.9462, 51.5032, -0.1228),
            ],
        ]);
    }

    /**
     * What the browser is handed, out of the attribute it is handed it in.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $html = $this->render();

        self::assertMatchesRegularExpression('/data-map="[^"]+"/', $html, 'the map should be configured');
        preg_match('/data-map="([^"]+)"/', $html, $found);

        /** @var array<string, mixed> $config */
        $config = json_decode(
            html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $config;
    }

    public function testThePinsAreColouredAndNotNumbered(): void
    {
        $config = $this->payload();

        self::assertCount(2, $config['markers'], 'a route has two ends');
        self::assertSame(self::ORIGIN_PIN, $config['markers'][0]['colour'], 'green where you leave');
        self::assertSame(self::DESTINATION_PIN, $config['markers'][1]['colour'], 'red where you land');
    }

    /**
     * Each pin says which city it is, in the order the route reads.
     *
     * The order matters as much as the names: swapped, the map would name both
     * places and put them the wrong way round, which is worse than the "1" and
     * "2" this replaced.
     */
    public function testEachPinIsNamed(): void
    {
        $config = $this->payload();

        self::assertSame('New York', $config['markers'][0]['label'], 'the origin pin');
        self::assertSame('London', $config['markers'][1]['label'], 'the destination pin');
    }

    /**
     * The label's font has to be one the map's style ships.
     *
     * This is the failure worth a test because it is silent: given a font the
     * style has no glyphs for, Mapbox draws no label at all and reports
     * nothing. The stack asserted here is one Mapbox Streets uses in four of
     * its own layers, and it was checked against the glyph endpoint -- 200, and
     * 43KB of glyphs -- rather than assumed.
     */
    public function testTheLabelAsksForAFontTheStyleHas(): void
    {
        $config = $this->payload();

        self::assertSame(
            ['DIN Pro Bold', 'Arial Unicode MS Bold'],
            $config['label']['font'],
            'a font the style does not ship draws nothing, silently',
        );

        // A halo, because a label crosses land, water and roads on one map and
        // no single colour reads on all three.
        self::assertNotSame(
            $config['label']['colour'],
            $config['label']['halo'],
            'the halo has to contrast with the text it outlines',
        );
        self::assertGreaterThan(0, $config['label']['size']);
    }

    public function testTheLegendNamesBothEndsInOrder(): void
    {
        $html = $this->render();

        $from = strpos($html, 'place__legend-item--from');
        $to = strpos($html, 'place__legend-item--to');

        self::assertNotFalse($from, 'the legend should mark the origin');
        self::assertNotFalse($to, 'the legend should mark the destination');
        self::assertLessThan($to, $from, 'where you leave should be read first');

        // Each name inside its own entry, so the two cannot be swapped without
        // this noticing.
        $origin = substr($html, $from, $to - $from);

        self::assertStringContainsString('New York', $origin);
        self::assertStringNotContainsString('London', $origin);
        self::assertStringContainsString('London', substr($html, $to));
    }

    /**
     * The path reaches the map whole, and as a curve.
     *
     * Two points would be a straight line, which is the wrong shape on this
     * projection -- see GreatCircleTest. The only question here is whether the
     * template handed over the arc or just its ends.
     */
    public function testTheFlightPathIsDrawnAsACurve(): void
    {
        $config = $this->payload();

        self::assertNotEmpty($config['paths'], 'the flight path should be drawn');
        self::assertGreaterThan(
            2,
            count($config['paths'][0]),
            'a curve needs more than the two ends',
        );

        // And in the order the map reads them, which is not the order the rest
        // of this codebase does.
        self::assertSame([-73.9462, 40.7019], $config['paths'][0][0], 'a path point is [lon, lat]');
    }

    /**
     * A page that never runs the script still has a map, and a describable one.
     *
     * This is what a crawler gets now that the map is a canvas, and it is why
     * the flat picture was kept when the map became interactive.
     */
    public function testAScriptlessPageStillGetsADescribedMap(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<noscript>', $html);

        preg_match('#<noscript>(.*?)</noscript>#s', $html, $fallback);
        self::assertNotEmpty($fallback, 'the fallback should be findable');

        self::assertStringContainsString('api.mapbox.com', $fallback[1], 'a flat picture of the same map');
        self::assertMatchesRegularExpression(
            '/alt="[^"]*New York[^"]*London[^"]*"/',
            $fallback[1],
            'and it should say what it shows',
        );
    }

    /**
     * The legend's dots stand for the pins, so they have to be the pins'
     * colours.
     *
     * The two live in different files -- the marker colour in the template that
     * feeds the map, the dot in the stylesheet -- and a dot that only nearly
     * matches is worse than none, because the reader has to work out whether
     * they are the same thing. Nothing else would catch that drift: both files
     * would still be valid and the page would still render.
     */
    public function testTheLegendDotsAreThePinColours(): void
    {
        $config = $this->payload();
        $css = (string) file_get_contents(Helper::getRootDir() . '/frontend/css/main.css');

        $pairs = [
            'place__legend-item--from' => $config['markers'][0]['colour'],
            'place__legend-item--to' => $config['markers'][1]['colour'],
        ];

        foreach ($pairs as $class => $colour) {
            self::assertMatchesRegularExpression(
                '/\.' . $class . '\s*\{[^}]*' . ltrim($colour, '#') . '/i',
                $css,
                $class . ' should be drawn in the colour its pin is: ' . $colour,
            );
        }
    }
}
