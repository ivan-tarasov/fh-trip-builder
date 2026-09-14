<?php

declare(strict_types=1);

namespace TripBuilder\View;

/**
 * A line small enough to read without axes.
 *
 * Drawn in the currency panel, under the sentence that says whose rates these
 * are and when they were published, because that is where a reader is already
 * asking how much to trust the numbers on the page (B2.2, #105).
 *
 * Points rather than a picture: this returns the `points` attribute of an SVG
 * `<polyline>` and nothing else. No library, no canvas, no request -- the same
 * reasoning as `Polyline` and `GreatCircle`, which draw their own lines because
 * the drawing is twenty lines of arithmetic and a dependency is not.
 *
 * There are no axes and there is no scale, deliberately. A sparkline says
 * "which way, and how steadily"; the numbers either side of it say how much.
 * Putting ticks on something 36 pixels tall would make it a bad chart instead
 * of a good line.
 */
final class Sparkline
{
    /** The box the line is drawn in, and the one the template must declare. */
    public const int WIDTH = 220;
    public const int HEIGHT = 36;

    /**
     * Room for the stroke at the top and bottom.
     *
     * Without it the highest and lowest points are drawn exactly on the edge
     * and the rounded cap is sliced in half by the viewBox.
     */
    private const float PADDING = 2.0;

    /**
     * A flat line sits here.
     *
     * A currency that has not moved, or has one reading, has no shape to draw.
     * The middle is the honest place for it: drawing it at the bottom would
     * read as a collapse and at the top as a peak.
     */
    private const float FLAT = self::HEIGHT / 2;

    /**
     * The polyline, or null when there is nothing worth drawing.
     *
     * One point is not a line. It is also exactly what this table held before
     * the backfill, which is the whole reason B2.2 waited for B2.1.
     *
     * @param list<float> $values oldest first
     */
    public static function points(array $values): ?string
    {
        if (count($values) < 2) {
            return null;
        }

        $low = min($values);
        $high = max($values);
        $span = $high - $low;
        $height = self::HEIGHT - 2 * self::PADDING;
        $step = self::WIDTH / (count($values) - 1);

        $points = [];

        foreach ($values as $i => $value) {
            // SVG counts y downwards, so the highest rate is the smallest y.
            $y = $span <= 0.0
                ? self::FLAT
                : self::PADDING + (1 - ($value - $low) / $span) * $height;

            $points[] = self::round($i * $step) . ',' . self::round($y);
        }

        return implode(' ', $points);
    }

    /**
     * To a tenth of a pixel.
     *
     * These go into an attribute printed on every page that opens the panel,
     * and a float printed whole is seventeen significant figures of it. A
     * tenth of a pixel is finer than anything a 36-pixel line can show.
     */
    private static function round(float $value): string
    {
        return (string) round($value, 1);
    }
}
