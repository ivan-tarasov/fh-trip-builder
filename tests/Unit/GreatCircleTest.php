<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\GreatCircle;

/**
 * The line the route map draws between two cities.
 *
 * The coordinates below are the real city centres this app computes -- the mean
 * of each city's airports, as CityRepository::byCode() returns it -- so the
 * assertions are about routes that exist rather than about invented geometry.
 *
 * Two things here are worth a test and neither is the arithmetic. The first is
 * that the arc bends the right way, which is the entire reason the curve
 * replaced a straight line: bending the wrong way, or not at all, is a drawing
 * that says the plane flies somewhere it does not. The second is the
 * antimeridian, where getting it wrong does not draw a slightly wrong line --
 * it either draws one the whole way round the world through Africa, or sends
 * the map service a longitude of 241 and loses the image altogether. Both were
 * live failures before this existed.
 */
final class GreatCircleTest extends TestCase
{
    private const float NYC_LAT = 40.7019;
    private const float NYC_LON = -73.9462;
    private const float LON_LAT = 51.5032;
    private const float LON_LON = -0.1228;
    private const float TYO_LAT = 35.6612;
    private const float TYO_LON = 140.0860;
    private const float LAX_LAT = 33.9423;
    private const float LAX_LON = -118.4069;
    private const float SYD_LAT = -33.9329;
    private const float SYD_LON = 151.1799;

    /**
     * The arc goes north of the straight line, which on this route is the
     * difference between flying over Newfoundland and flying over open ocean.
     *
     * Compared at the halfway point against the average of the two ends, which
     * is where a straight line would put it. Roughly seven degrees of latitude
     * on this route -- around 800km -- so the margin is not a rounding effect.
     */
    public function testTheArcBendsTowardsThePole(): void
    {
        $runs = GreatCircle::segments(self::NYC_LAT, self::NYC_LON, self::LON_LAT, self::LON_LON, 16);

        self::assertCount(1, $runs, 'this route crosses no edge');

        $middle = $runs[0][8];
        $straight = (self::NYC_LAT + self::LON_LAT) / 2;

        self::assertGreaterThan(
            $straight + 3,
            $middle['lat'],
            'the midpoint should sit well north of the straight line',
        );
    }

    /**
     * And south of it below the equator, which is the same fact and the case a
     * hard-coded "add latitude" would get backwards.
     */
    public function testTheArcBendsTowardsTheNearerPoleInTheSouth(): void
    {
        // Sydney to a point at the same latitude well to its west: the great
        // circle between two southern points bows south.
        $runs = GreatCircle::segments(self::SYD_LAT, self::SYD_LON, self::SYD_LAT, 90.0, 16);

        self::assertCount(1, $runs);
        self::assertLessThan(
            self::SYD_LAT,
            $runs[0][8]['lat'],
            'between two southern points the arc should bow south',
        );
    }

    public function testTheEndsAreExactlyWhereTheyWereGiven(): void
    {
        $runs = GreatCircle::segments(self::NYC_LAT, self::NYC_LON, self::LON_LAT, self::LON_LON, 16);
        $points = $runs[0];

        self::assertSame(self::NYC_LAT, $points[0]['lat']);
        self::assertSame(self::NYC_LON, $points[0]['lon']);
        self::assertSame(self::LON_LAT, $points[count($points) - 1]['lat']);
        self::assertSame(self::LON_LON, $points[count($points) - 1]['lon']);
    }

    public function testTheArcIsDrawnInTheNumberOfPiecesAsked(): void
    {
        foreach ([1, 4, 16, 64] as $segments) {
            $runs = GreatCircle::segments(
                self::NYC_LAT,
                self::NYC_LON,
                self::LON_LAT,
                self::LON_LON,
                $segments,
            );

            self::assertCount($segments + 1, $runs[0], $segments . ' pieces need one more point');
        }
    }

    /**
     * A route over the antimeridian comes back cut in two, and the cut is
     * clean: out at one edge, in at the other, at the same latitude.
     *
     * Unsplit, this is not a cosmetic problem. The honest longitudes run past
     * +180, which the map service rejects with a 400 and no image; wrapped
     * without splitting, the line is drawn back around the world through
     * Africa.
     */
    public function testARouteOverTheAntimeridianIsCutAtTheEdge(): void
    {
        $runs = GreatCircle::segments(self::TYO_LAT, self::TYO_LON, self::LAX_LAT, self::LAX_LON, 16);

        self::assertCount(2, $runs, 'Tokyo to Los Angeles crosses the edge');

        $first = $runs[0];
        $second = $runs[1];
        $out = $first[count($first) - 1];
        $in = $second[0];

        self::assertSame(180.0, $out['lon'], 'the first run should leave at +180');
        self::assertSame(-180.0, $in['lon'], 'the second should come back at -180');
        self::assertSame($out['lat'], $in['lat'], 'and at the same latitude');

        // The ends are still the cities asked for.
        self::assertSame(self::TYO_LON, $first[0]['lon']);
        self::assertSame(self::LAX_LON, $second[count($second) - 1]['lon']);
    }

    /**
     * A long westward route is not a crossing.
     *
     * Sydney to London runs from +151 to nearly zero, the whole way across
     * Asia, and every longitude falls the whole way -- so it must come back as
     * one run. This is the case a naive "the longitudes are far apart, so split"
     * would cut in half.
     */
    public function testALongRouteThatCrossesNoEdgeIsNotCut(): void
    {
        $runs = GreatCircle::segments(self::SYD_LAT, self::SYD_LON, self::LON_LAT, self::LON_LON, 16);

        self::assertCount(1, $runs, 'Sydney to London crosses no edge');
    }

    /**
     * Nothing may leave the map, whichever route it is.
     */
    public function testEveryLongitudeStaysOnTheMap(): void
    {
        $routes = [
            'New York to London' => [self::NYC_LAT, self::NYC_LON, self::LON_LAT, self::LON_LON],
            'Tokyo to Los Angeles' => [self::TYO_LAT, self::TYO_LON, self::LAX_LAT, self::LAX_LON],
            'Los Angeles to Tokyo' => [self::LAX_LAT, self::LAX_LON, self::TYO_LAT, self::TYO_LON],
            'Sydney to London' => [self::SYD_LAT, self::SYD_LON, self::LON_LAT, self::LON_LON],
            'Sydney to Los Angeles' => [self::SYD_LAT, self::SYD_LON, self::LAX_LAT, self::LAX_LON],
        ];

        foreach ($routes as $name => [$fromLat, $fromLon, $toLat, $toLon]) {
            foreach (GreatCircle::segments($fromLat, $fromLon, $toLat, $toLon, 16) as $run) {
                foreach ($run as $point) {
                    self::assertGreaterThanOrEqual(-180, $point['lon'], $name . ' left the map');
                    self::assertLessThanOrEqual(180, $point['lon'], $name . ' left the map');
                    self::assertGreaterThanOrEqual(-90, $point['lat'], $name . ' left the map');
                    self::assertLessThanOrEqual(90, $point['lat'], $name . ' left the map');
                }
            }
        }
    }

    /**
     * Two ends in the same place must not divide by zero.
     *
     * Nothing generates this -- a route to where you already are is a 404
     * before it reaches a map -- but the interpolation divides by the sine of
     * the distance between the ends, and the answer at zero is a crash rather
     * than a wrong picture.
     */
    public function testARouteToWhereYouAlreadyAreIsStillTwoPoints(): void
    {
        $runs = GreatCircle::segments(self::NYC_LAT, self::NYC_LON, self::NYC_LAT, self::NYC_LON, 16);

        self::assertCount(1, $runs);
        self::assertCount(2, $runs[0]);
        self::assertSame(self::NYC_LAT, $runs[0][0]['lat']);
    }

    /**
     * Coordinates are rounded to what a map can draw, because seventeen of
     * these go in a URL.
     */
    public function testCoordinatesAreRoundedForTheAddressBar(): void
    {
        $runs = GreatCircle::segments(self::NYC_LAT, self::NYC_LON, self::LON_LAT, self::LON_LON, 16);

        foreach ($runs[0] as $point) {
            self::assertSame(round($point['lat'], 4), $point['lat']);
            self::assertSame(round($point['lon'], 4), $point['lon']);
        }
    }
}
