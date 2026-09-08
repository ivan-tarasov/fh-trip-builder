<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\GreatCircle;
use TripBuilder\Helper;
use TripBuilder\View\TwigRenderer;

/**
 * The route map: two pins, a line, and a legend saying which pin is which.
 *
 * The pins carry no number. They used to carry "1" and "2", which name neither
 * of the two places on a map of two places -- and the map service cannot letter
 * a pin, so what tells them apart is colour and what names them is the legend.
 *
 * That makes the legend load-bearing rather than decorative: with it gone, or
 * with its two entries the wrong way round, the map says nothing at all. Hence
 * a test, which also holds the one coupling that spans two files -- the dot
 * colours in the stylesheet stand for the pin colours in this template, and
 * nothing but agreement makes them mean anything.
 */
final class RouteMapRenderTest extends TestCase
{
    /** Sampled from a rendered pin; see the note in main.css. */
    private const string ORIGIN_PIN = '#0EB600';
    private const string DESTINATION_PIN = '#EC3735';

    protected function setUp(): void
    {
        new Config('common');
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

    public function testThePinsAreColouredAndNotNumbered(): void
    {
        $html = $this->render();

        self::assertStringContainsString('pm2gnm~', $html, 'the origin should be the green pin');
        self::assertStringContainsString('pm2rdm&', $html, 'the destination should be the red pin');

        // "pm2blm1" and "pm2blm2" were what this drew before. A number on the
        // end of a marker style is the thing being removed, so it is asserted
        // gone rather than merely not written.
        self::assertDoesNotMatchRegularExpression(
            '/pm2[a-z]{2}m\d/',
            $html,
            'a pin should carry no number',
        );
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
     * The line is drawn, and drawn as a curve.
     *
     * Two points would be a straight line, which is the wrong shape on this
     * projection -- see GreatCircleTest. Here the only question is whether the
     * template passed the whole path to the map or just its ends.
     */
    public function testTheFlightPathIsDrawnAsACurve(): void
    {
        $html = $this->render();

        self::assertStringContainsString('&pl=c:0F766EFF,w:5,', $html, 'the path should be drawn');

        preg_match('/&pl=([^"&]*)/', $html, $found);
        self::assertNotEmpty($found, 'the polyline should be findable');

        $coordinates = explode(',', $found[1]);
        // Two style fields, then a longitude and a latitude per point.
        $points = (count($coordinates) - 2) / 2;

        self::assertGreaterThan(
            2,
            $points,
            'a curve needs more than the two ends; ' . $points . ' points is a straight line',
        );
    }

    /**
     * The legend's dots stand for the pins, so they have to be the pins'
     * colours.
     *
     * The two live in different files -- the marker style in this template, the
     * dot in the stylesheet -- and a dot that only nearly matches is worse than
     * none, because the reader has to work out whether they are the same thing.
     * Nothing else would catch that drift: both files would still be valid and
     * the page would still render.
     */
    public function testTheLegendDotsAreThePinColours(): void
    {
        $css = (string) file_get_contents(Helper::getRootDir() . '/frontend/css/main.css');

        foreach (
            [
                'place__legend-item--from' => self::ORIGIN_PIN,
                'place__legend-item--to' => self::DESTINATION_PIN,
            ] as $class => $colour
        ) {
            self::assertMatchesRegularExpression(
                '/\.' . $class . '\s*\{[^}]*' . $colour . '/i',
                $css,
                $class . ' should be drawn in ' . $colour,
            );
        }
    }
}
