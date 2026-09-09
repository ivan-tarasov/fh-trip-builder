<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\GreatCircle;
use TripBuilder\Polyline;

/**
 * Google's encoded polyline format, which is what a Mapbox path is drawn from.
 *
 * Tested against the example in Google's own specification rather than against
 * a string this encoder produced, because that is the only fixture here that
 * was not written by the code under test. Everything else about this format is
 * self-consistent: a wrong shift, a swapped pair or a dropped continuation bit
 * all produce a string that decodes cleanly and draws the wrong line.
 */
final class PolylineTest extends TestCase
{
    /**
     * The worked example from the format's specification.
     *
     * (38.5, -120.2), (40.7, -120.95), (43.252, -126.453) encodes to
     * `_p~iF~ps|U_ulLnnqC_mqNvxq`@` -- and the backtick in it is why this is
     * built with a concatenation rather than written as one literal.
     */
    public function testTheSpecificationsOwnExample(): void
    {
        $points = [
            ['lat' => 38.5, 'lon' => -120.2],
            ['lat' => 40.7, 'lon' => -120.95],
            ['lat' => 43.252, 'lon' => -126.453],
        ];

        $expected = '_p~iF~ps|U_ulLnnqC_mqNvxq' . chr(96) . '@';

        self::assertSame($expected, Polyline::encode($points));
    }

    /**
     * One point is a legal polyline and encodes as its own difference from
     * zero.
     */
    public function testASinglePoint(): void
    {
        self::assertSame('_p~iF~ps|U', Polyline::encode([['lat' => 38.5, 'lon' => -120.2]]));
    }

    public function testNoPointsEncodesToNothing(): void
    {
        self::assertSame('', Polyline::encode([]));
    }

    /**
     * Latitude before longitude, which is the reverse of the order a map URL
     * takes them in.
     *
     * The one mistake in this encoder that still produces a valid string, so it
     * is asserted directly: swapping the pair must not give the same answer.
     */
    public function testLatitudeIsEncodedBeforeLongitude(): void
    {
        $oneWay = Polyline::encode([['lat' => 38.5, 'lon' => -120.2]]);
        $other = Polyline::encode([['lat' => -120.2, 'lon' => 38.5]]);

        self::assertNotSame($oneWay, $other);
    }

    /**
     * The route map's own path, which is what this exists for.
     *
     * Not a fixed expected string -- that would be asserting the encoder
     * against itself. What is checked is that the whole arc survives: 17 points
     * in, and an encoding short enough to be worth having chosen over GeoJSON.
     */
    public function testTheRouteArcEncodesToSomethingShort(): void
    {
        $runs = GreatCircle::segments(40.7019, -73.9462, 51.5032, -0.1228, 16);

        self::assertCount(1, $runs);
        self::assertCount(17, $runs[0]);

        $encoded = Polyline::encode($runs[0]);

        self::assertNotSame('', $encoded);

        // Every character has to survive a URL, and the format only emits
        // printable ASCII from "?" up.
        self::assertMatchesRegularExpression('/^[\x3F-\x7E]+$/', $encoded);

        // The GeoJSON this was chosen over: 17 points at roughly 22 characters
        // a pair, before escaping.
        self::assertLessThan(
            17 * 22,
            strlen($encoded),
            'the encoding should be shorter than the coordinates it stands for',
        );
    }

    /**
     * A route crossing the antimeridian is two runs, and each encodes on its
     * own -- the paths are two overlays on the map, not one line.
     */
    public function testEachRunOfASplitRouteEncodesSeparately(): void
    {
        $runs = GreatCircle::segments(35.6612, 140.0860, 33.9423, -118.4069, 16);

        self::assertCount(2, $runs);

        $first = Polyline::encode($runs[0]);
        $second = Polyline::encode($runs[1]);

        self::assertNotSame('', $first);
        self::assertNotSame('', $second);
        self::assertNotSame($first, $second);
    }
}
