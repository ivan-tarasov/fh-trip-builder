<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\View\SearchFilterPanel;

/**
 * The two sliders, which are the arithmetic in the sidebar.
 *
 * They lived in `SearchController` until E27 (#200) and could only be reached
 * by running a whole search — 500,000 flights and a rendered page to check the
 * position of a handle. They are pure: bounds and a chosen value in, a control
 * out.
 *
 * Everything here is in minutes. `money` would pull in the active currency,
 * and the rounding being checked has nothing to do with which one it is.
 */
final class SearchFilterPanelTest extends TestCase
{
    /** Fractions of an hour, as the sidebar uses. */
    private const array STEPS = [5, 10, 15, 30, 60, 120, 180, 360, 720];

    public function testThereIsNoControlWithoutBounds(): void
    {
        self::assertNull(SearchFilterPanel::sliderOption(null, null, self::STEPS, 'minutes'));
        self::assertNull(SearchFilterPanel::rangeOption(null, null, self::STEPS, 'minutes'));
    }

    /**
     * Nothing to drag between when every flight lasts the same, so no control.
     */
    public function testAFlatSpreadDrawsNothing(): void
    {
        $flat = ['min' => 300, 'max' => 300, 'floor_max' => 300, 'ceiling_min' => 300];

        self::assertNull(SearchFilterPanel::sliderOption($flat, null, self::STEPS, 'minutes'));
        self::assertNull(SearchFilterPanel::rangeOption($flat, null, self::STEPS, 'minutes'));
    }

    /**
     * Unless a filter is applied, and then it has to stay whatever the spread.
     *
     * The control is the way back out of a narrow filter, and a form that drops
     * the input drops the filter without saying so — which reads as the search
     * quietly widening on its own.
     */
    public function testAFlatSpreadKeepsItsControlWhenAFilterIsApplied(): void
    {
        $flat = ['min' => 300, 'max' => 300, 'floor_max' => 300, 'ceiling_min' => 300];

        self::assertNotNull(SearchFilterPanel::sliderOption($flat, '240', self::STEPS, 'minutes'));
        self::assertNotNull(SearchFilterPanel::rangeOption($flat, '60;240', self::STEPS, 'minutes'));
    }

    /**
     * The top end always reaches the longest flight on offer.
     *
     * The track is rounded so its labels read as "57h" rather than "56h 53m".
     * Round the top *down* and the longest flight the search found sits past
     * the right-hand end, so a handle dragged the whole way still filters it
     * out — a result that exists and no position of the control can reach.
     *
     * @param array{min: int, max: int} $bound
     */
    #[DataProvider('spreads')]
    public function testTheTrackAlwaysReachesTheLongest(array $bound): void
    {
        $full = $bound + ['floor_max' => $bound['min'], 'ceiling_min' => $bound['max']];

        $slider = SearchFilterPanel::sliderOption($full, null, self::STEPS, 'minutes');
        self::assertNotNull($slider);
        self::assertGreaterThanOrEqual($bound['max'], $slider['max'], 'the track ends below the longest');

        $range = SearchFilterPanel::rangeOption($full, null, self::STEPS, 'minutes');
        self::assertNotNull($range);
        self::assertGreaterThanOrEqual($bound['max'], $range['max'], 'the track ends below the longest');
    }

    /**
     * The bottom end rounds the opposite way on the two controls, and that is
     * deliberate — see the note on `Helper::sliderScale()`.
     *
     * A single handle is a ceiling, and the far left is the one place a visitor
     * is certain to drag to. Rounded *down*, "up to 8h" over a shortest flight
     * of 8h 10m matches nothing at all. A range has a floor down there instead,
     * and a floor under everything excludes nothing, so it rounds down and
     * keeps the true spread visible.
     *
     * @param array{min: int, max: int} $bound
     */
    #[DataProvider('spreads')]
    public function testTheBottomEndRoundsTowardsWhicheverHandleIsThere(array $bound): void
    {
        $full = $bound + ['floor_max' => $bound['min'], 'ceiling_min' => $bound['max']];

        $slider = SearchFilterPanel::sliderOption($full, null, self::STEPS, 'minutes');
        self::assertNotNull($slider);
        self::assertGreaterThanOrEqual(
            $bound['min'],
            $slider['min'],
            'a ceiling below the shortest flight matches nothing',
        );

        $range = SearchFilterPanel::rangeOption($full, null, self::STEPS, 'minutes');
        self::assertNotNull($range);
        self::assertLessThanOrEqual(
            $bound['min'],
            $range['min'],
            'a floor above the shortest flight hides it',
        );
    }

    /** @return array<string, array{array{min: int, max: int}}> */
    public static function spreads(): array
    {
        $spreads = [
            'a short hop' => ['min' => 61, 'max' => 119],
            'the real one this was written against' => ['min' => 499, 'max' => 3413],
            'the other real one' => ['min' => 1107, 'max' => 15941],
            'a single awkward minute apart' => ['min' => 1439, 'max' => 1440],
            'wider than the largest step' => ['min' => 7, 'max' => 20161],
        ];

        // And a spread of arbitrary ones, because the rounding is where an
        // off-by-one hides and five hand-picked pairs will not find it.
        for ($low = 1; $low < 900; $low += 97) {
            $spreads[sprintf('%d to %d', $low, $low * 7 + 13)] = ['min' => $low, 'max' => $low * 7 + 13];
        }

        return array_map(static fn(array $bound): array => [$bound], $spreads);
    }

    /**
     * A ceiling above everything on offer widens the track to reach it.
     *
     * A shared link can carry a filter looser than this search's own spread.
     * Clamping it would move the handle somewhere nobody asked for and change
     * the filter on the next Apply.
     */
    public function testACeilingPastTheEndWidensTheTrack(): void
    {
        $slider = SearchFilterPanel::sliderOption(
            ['min' => 60, 'max' => 600, 'floor_max' => 60, 'ceiling_min' => 600],
            '5000',
            self::STEPS,
            'minutes',
        );

        self::assertNotNull($slider);
        self::assertGreaterThanOrEqual(5000, $slider['max']);
        self::assertSame(5000, $slider['value']);
    }

    /** And one below where the track starts pulls the start down to it. */
    public function testACeilingBelowTheStartMovesTheStart(): void
    {
        $slider = SearchFilterPanel::sliderOption(
            ['min' => 600, 'max' => 1200, 'floor_max' => 600, 'ceiling_min' => 1200],
            '90',
            self::STEPS,
            'minutes',
        );

        self::assertNotNull($slider);
        self::assertLessThanOrEqual(90, $slider['min']);
        self::assertSame(90, $slider['value']);
    }

    /**
     * `on` says whether the visitor has narrowed anything, and the sidebar
     * opens the section it is true for. Wrong, and an applied filter hides
     * behind a collapsed heading with only the result count as a clue.
     */
    public function testAHandleAtTheEndIsNotAFilter(): void
    {
        $bound = ['min' => 60, 'max' => 600, 'floor_max' => 60, 'ceiling_min' => 600];

        $untouched = SearchFilterPanel::sliderOption($bound, null, self::STEPS, 'minutes');
        self::assertNotNull($untouched);
        self::assertFalse($untouched['on']);

        $narrowed = SearchFilterPanel::sliderOption($bound, '300', self::STEPS, 'minutes');
        self::assertNotNull($narrowed);
        self::assertTrue($narrowed['on']);
    }

    /**
     * Every shape FlightFilters accepts, because the control has to show the
     * state the filter actually applied. A bare number is a ceiling with no
     * floor under it.
     *
     * @param array{from: int, to: int} $expected
     */
    #[DataProvider('ranges')]
    public function testARangeIsReadInEveryShapeItIsWrittenIn(mixed $value, array $expected): void
    {
        $range = SearchFilterPanel::rangeOption(
            ['min' => 0, 'max' => 1200, 'floor_max' => 600, 'ceiling_min' => 60],
            $value,
            self::STEPS,
            'minutes',
        );

        self::assertNotNull($range);
        self::assertSame($expected['from'], $range['from']);
        self::assertSame($expected['to'], $range['to']);
    }

    /** @return array<string, array{mixed, array{from: int, to: int}}> */
    public static function ranges(): array
    {
        return [
            'a bare ceiling rests the floor at the bottom' => ['240', ['from' => 0, 'to' => 240]],
            'a semicolon pair' => ['120;480', ['from' => 120, 'to' => 480]],
            'a hyphen pair' => ['120-480', ['from' => 120, 'to' => 480]],
            'nothing set opens to the full track' => [null, ['from' => 0, 'to' => 1200]],
            'nonsense is nothing set' => ['not-a-range', ['from' => 0, 'to' => 1200]],
        ];
    }

    /**
     * The handles cannot be dragged into a stretch of track that can only
     * return nothing, and neither limit may leave the track.
     */
    public function testTheHandleLimitsStayOnTheTrack(): void
    {
        $range = SearchFilterPanel::rangeOption(
            ['min' => 45, 'max' => 1470, 'floor_max' => 700, 'ceiling_min' => 200],
            null,
            self::STEPS,
            'minutes',
        );

        self::assertNotNull($range);
        self::assertGreaterThanOrEqual($range['min'], $range['floor_max']);
        self::assertLessThanOrEqual($range['max'], $range['floor_max']);
        self::assertGreaterThanOrEqual($range['min'], $range['ceiling_min']);
        self::assertLessThanOrEqual($range['max'], $range['ceiling_min']);
    }
}
