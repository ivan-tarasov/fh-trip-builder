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
