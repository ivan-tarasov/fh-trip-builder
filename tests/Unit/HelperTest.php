<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

final class HelperTest extends TestCase
{
    #[DataProvider('pluralProvider')]
    public function testPlural(string $expected, int $number, string $singular, ?string $plural, bool $showNumber): void
    {
        self::assertSame($expected, Helper::plural($number, $singular, $plural, $showNumber));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: string|null, 4: bool}>
     */
    public static function pluralProvider(): array
    {
        return [
            'singular' => ['flight', 1, 'flight', null, false],
            'plural' => ['flights', 2, 'flight', null, false],
            'zero is plural' => ['flights', 0, 'flight', null, false],
            'singular with number' => ['1 flight', 1, 'flight', null, true],
            'plural with number' => ['42 flights', 42, 'flight', null, true],
            'custom plural' => ['children', 3, 'child', 'children', false],
            'custom plural with count' => ['3 children', 3, 'child', 'children', true],
        ];
    }

    #[DataProvider('bookingIdProvider')]
    public function testBookingIdToString(string $expected, int $id): void
    {
        self::assertSame($expected, Helper::bookingIdToString($id));
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    public static function bookingIdProvider(): array
    {
        return [
            [ '100-001', 100001 ],
            [ '999-999', 999999 ],
            [ '1-234', 1234 ],
            [ '12', 12 ],
        ];
    }

    #[DataProvider('utcProvider')]
    public function testGetUTCTime(string $expected, int|float $offset): void
    {
        self::assertSame($expected, Helper::getUTCTime($offset));
    }

    /**
     * @return array<string, array{0: string, 1: int|float}>
     */
    public static function utcProvider(): array
    {
        return [
            'utc' => ['GMT+00:00', 0],
            'positive whole' => ['GMT+02:00', 2],
            'negative whole' => ['GMT-05:00', -5],
            'half hour' => ['GMT+05:30', 5.5],
            'negative half' => ['GMT-03:30', -3.5],
            'quarter' => ['GMT+05:45', 5.75],
        ];
    }

    #[DataProvider('cardSchemeProvider')]
    public function testCardScheme(string $expected, string $number): void
    {
        self::assertSame($expected, Helper::cardScheme($number));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cardSchemeProvider(): array
    {
        return [
            'visa' => ['Visa', '4563 4563 4563 4567'],
            'mastercard' => ['Mastercard', '5555 5555 5555 4444'],
            // The 2-series was added to Mastercard's range in 2017 and is easy
            // to miss, which lands a real card under the generic label.
            'mastercard 2-series' => ['Mastercard', '2221000000000009'],
            'amex' => ['Amex', '3782 822463 10005'],
            'discover' => ['Discover', '6011111111111117'],
            // Named from the prefix alone, so a partly-typed number already
            // knows what it is — that is what lets the form show the logo.
            'a single digit is enough for visa' => ['Visa', '4'],
            'nothing yet' => ['Card', ''],
            'no range matches' => ['Card', '9999999999999999'],
        ];
    }

    #[DataProvider('luhnProvider')]
    public function testIsLuhnValid(bool $expected, string $number): void
    {
        self::assertSame($expected, Helper::isLuhnValid($number));
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function luhnProvider(): array
    {
        return [
            'a visa test number' => [true, '4563456345634567'],
            'spaces are not digits' => [true, '4563 4563 4563 4567'],
            'a mastercard test number' => [true, '5555555555554444'],
            'a 15-digit amex' => [true, '378282246310005'],
            // The whole point: one digit out and the check digit disagrees.
            'one digit mistyped' => [false, '4563456345634568'],
            'two digits transposed' => [false, '4563456345634657'],
            'too short to be a card' => [false, '4563456'],
            'too long to be a card' => [false, '45634563456345671234'],
            'letters' => [false, 'not-a-card'],
            'empty' => [false, ''],
        ];
    }

    #[DataProvider('hoursAndMinutesProvider')]
    public function testHoursAndMinutes(string $expected, int $minutes): void
    {
        self::assertSame($expected, Helper::hoursAndMinutes($minutes));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function hoursAndMinutesProvider(): array
    {
        return [
            'nothing' => ['0m', 0],
            'under an hour' => ['45m', 45],
            'exactly an hour' => ['1h', 60],
            'hour and change' => ['1h 30m', 90],
            'whole day' => ['24h', 1440],
            // Hours run past 24 rather than rolling into days: a 33-hour trip
            // is easier to compare against a 26-hour one than "1d 9h" is.
            'more than a day' => ['33h 20m', 2000],
        ];
    }

    #[DataProvider('sliderCaptionProvider')]
    public function testSliderCaption(string $expected, string $kind, int $from, ?int $to, int $min, int $max): void
    {
        self::assertSame($expected, Helper::sliderCaption($kind, $from, $to, $min, $max));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int|null, 4: int, 5: int}>
     */
    public static function sliderCaptionProvider(): array
    {
        return [
            // One handle sits under a label that already says "Up to", so the
            // pill must not say it twice.
            'single handle' => ['6h', 'minutes', 360, null, 50, 360],
            'single handle, money' => ['$12,000', 'money', 12000, null, 500, 20000],
            // Both ends open: there is no filter, so the pill reads as the top
            // of what is on offer rather than as a limit someone chose.
            'range at both ends' => ['Up to 6h', 'minutes', 50, 360, 50, 360],
            'floor moved' => ['From 2h', 'minutes', 120, 360, 50, 360],
            'ceiling moved' => ['Up to 3h 20m', 'minutes', 50, 200, 50, 360],
            'both moved' => ['From 1h 30m to 4h', 'minutes', 90, 240, 50, 360],
            'both moved, money' => ['From $500 to $1,200', 'money', 500, 1200, 100, 5000],
            // A handle at or under its end excludes nothing, so it is not
            // announced. rangeOption() widens the bounds to contain whatever
            // was chosen, so this is the shape a stale floor arrives in.
            'floor at or below the minimum' => ['Up to 6h', 'minutes', 40, 360, 50, 360],
            'ceiling at or above the maximum' => ['From 2h', 'minutes', 120, 400, 50, 360],
        ];
    }

    /**
     * @param array{min: int, max: int, step: int} $expected
     * @param list<int> $steps
     */
    #[DataProvider('sliderScaleProvider')]
    public function testSliderScale(array $expected, int $low, int $high, array $steps, bool $ceilingOnly): void
    {
        self::assertSame($expected, Helper::sliderScale($low, $high, $steps, 40, $ceilingOnly));
    }

    /**
     * @return array<string, array{0: array{min: int, max: int, step: int}, 1: int, 2: int, 3: list<int>, 4: bool}>
     */
    public static function sliderScaleProvider(): array
    {
        $prices = [5, 10, 25, 50, 100, 250, 500, 1000];

        return [
            // The bug this exists to catch: a lone handle is a ceiling, and
            // rounding its bottom end down to 6000 puts the far-left position
            // under the 6042 flight, where the search can only answer nothing.
            'a ceiling never starts under the cheapest' => [
                ['min' => 6250, 'max' => 14250, 'step' => 250], 6042, 14181, $prices, true,
            ],
            // A floor there instead: under everything excludes nothing, so it
            // rounds the other way and the track shows the true spread.
            'a floor may start under the cheapest' => [
                ['min' => 6000, 'max' => 14250, 'step' => 250], 6042, 14181, $prices, false,
            ],
            'ends already on the step are left alone' => [
                ['min' => 6000, 'max' => 14000, 'step' => 250], 6000, 14000, $prices, true,
            ],
            // Everything costs the same: still a step wide, or the handle has
            // nowhere to go and the track draws as a dot.
            'a span of nothing is still draggable' => [
                ['min' => 5000, 'max' => 5005, 'step' => 5], 5000, 5000, $prices, true,
            ],
        ];
    }

    public function testACeilingScaleNeverStartsBelowWhatItMeasures(): void
    {
        // The property behind the examples above, over a spread of shapes. A
        // ceiling slider's far-left position is the one a visitor is certain to
        // drag to; if it sits under the cheapest result it can only ever answer
        // "no flights", however tidy the number looks.
        $steps = [5, 10, 25, 50, 100, 250, 500, 1000];

        foreach ([[6042, 14181], [1, 2], [999, 1000], [40, 360], [7, 7], [12345, 12346]] as [$low, $high]) {
            $scale = Helper::sliderScale($low, $high, $steps, 40, ceilingOnly: true);

            self::assertGreaterThanOrEqual(
                $low,
                $scale['min'],
                sprintf('a ceiling at %d would exclude everything down to %d', $scale['min'], $low),
            );
            self::assertGreaterThanOrEqual($high, $scale['max']);
            self::assertGreaterThan($scale['min'], $scale['max']);
        }
    }

    /**
     * @param list<int> $steps
     */
    #[DataProvider('sliderStepProvider')]
    public function testSliderStep(int $expected, int $span, array $steps): void
    {
        self::assertSame($expected, Helper::sliderStep($span, $steps, 40));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: list<int>}>
     */
    public static function sliderStepProvider(): array
    {
        $steps = [5, 10, 25, 50, 100];

        return [
            'a narrow span takes the smallest step' => [5, 40, $steps],
            'roughly forty positions' => [10, 400, $steps],
            'rounds up to an allowed step' => [25, 800, $steps],
            // Wider than the table can divide: the largest step, and more
            // positions than asked for, rather than an unusable fraction.
            'a span past the table' => [100, 100000, $steps],
            'a span of nothing still has a step' => [5, 0, $steps],
        ];
    }

    #[DataProvider('airportNameProvider')]
    public function testAirportNameAfterCity(string $expected, string $title, string $city): void
    {
        self::assertSame($expected, Helper::airportNameAfterCity($title, $city));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function airportNameProvider(): array
    {
        return [
            'city leads the name' => ['Airport Schiphol', 'Amsterdam Airport Schiphol', 'Amsterdam'],
            'city leads a longer name' => ['Gatwick Airport', 'London Gatwick Airport', 'London'],
            'name does not repeat the city' => ['Heathrow', 'Heathrow', 'London'],
            // Mid-name is not a prefix: cutting there would leave "Adolfo
            // Suarez -Barajas Airport".
            'city appears mid-name' => [
                'Adolfo Suarez Madrid-Barajas Airport',
                'Adolfo Suarez Madrid-Barajas Airport',
                'Madrid',
            ],
            // Whole words only, or the city would eat into the next one.
            'city is a prefix of the first word' => ['Santiago International', 'Santiago International', 'San'],
            'hyphen is not a word break' => ['Amsterdam-Schiphol', 'Amsterdam-Schiphol', 'Amsterdam'],
            'name is only the city' => ['', 'Karachi', 'Karachi'],
            'name is only the city, cased differently' => ['', 'karachi', 'Karachi'],
            'city cased differently' => ['Airport Schiphol', 'amsterdam Airport Schiphol', 'Amsterdam'],
            // Nothing to strip by, so nothing is stripped — the name must
            // survive whole rather than come back empty.
            'no city' => ['Paris Charles de Gaulle', 'Paris Charles de Gaulle', ''],
            'no name' => ['', '', 'London'],
        ];
    }

}
