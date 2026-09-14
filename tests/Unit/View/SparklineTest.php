<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\View\Sparkline;

/**
 * The arithmetic behind a line 36 pixels tall.
 *
 * Worth a test for the reason `Polyline` is: an off-by-one here produces a
 * string that is still a perfectly valid `points` attribute and draws the wrong
 * shape. Nobody reviewing a sparkline can tell an upside-down one from a
 * currency that fell (B2.2, #105).
 */
final class SparklineTest extends TestCase
{
    public function testOnePointIsNotALine(): void
    {
        self::assertNull(Sparkline::points([]));
        self::assertNull(Sparkline::points([1.5]));
    }

    public function testTheLineSpansTheWholeBox(): void
    {
        $points = self::pairs(Sparkline::points([1.0, 2.0, 3.0]));

        self::assertCount(3, $points);
        self::assertSame(0.0, $points[0][0]);
        self::assertSame((float) Sparkline::WIDTH, $points[2][0]);
    }

    /**
     * SVG counts y downwards, so a rising rate has to draw upwards.
     *
     * The one mistake that is invisible in the output and obvious on the page.
     */
    public function testARisingRateDrawsUpwards(): void
    {
        $points = self::pairs(Sparkline::points([1.0, 2.0, 3.0]));

        self::assertGreaterThan($points[1][1], $points[0][1]);
        self::assertGreaterThan($points[2][1], $points[1][1]);
    }

    public function testAFallingRateDrawsDownwards(): void
    {
        $points = self::pairs(Sparkline::points([3.0, 2.0, 1.0]));

        self::assertLessThan($points[1][1], $points[0][1]);
        self::assertLessThan($points[2][1], $points[1][1]);
    }

    /**
     * A currency that has not moved has no shape, and the middle is the only
     * honest place to draw it: the bottom reads as a collapse.
     */
    public function testAFlatRateSitsInTheMiddle(): void
    {
        foreach (self::pairs(Sparkline::points([2.0, 2.0, 2.0])) as $point) {
            self::assertSame(Sparkline::HEIGHT / 2.0, $point[1]);
        }
    }

    /**
     * The extremes stay inside the box, or the stroke is sliced by the viewBox.
     */
    public function testTheLineKeepsClearOfTheEdges(): void
    {
        $pairs = self::pairs(Sparkline::points([1.0, 5.0, 3.0]));

        self::assertNotEmpty($pairs);

        foreach ($pairs as $point) {
            self::assertGreaterThan(0, $point[1]);
            self::assertLessThan(Sparkline::HEIGHT, $point[1]);
        }
    }

    /**
     * This string is printed into an attribute, so it has to stay short and
     * has to stay a number — `1.0E-5` is a valid float and not a coordinate.
     */
    public function testEveryCoordinateIsPrintable(): void
    {
        $points = Sparkline::points([0.000001, 0.0000013, 0.0000011]);

        self::assertNotNull($points);
        self::assertMatchesRegularExpression('/^(\d+(\.\d)? \d+(\.\d)?|\d+(\.\d)?,\d+(\.\d)?)( |$)/', $points . ' ');
        self::assertStringNotContainsString('E', strtoupper($points));
    }

    /**
     * @return list<array{float, float}>
     */
    private static function pairs(?string $points): array
    {
        self::assertNotNull($points);

        return array_map(
            static function (string $point): array {
                [$x, $y] = explode(',', $point);

                return [(float) $x, (float) $y];
            },
            explode(' ', $points),
        );
    }
}
