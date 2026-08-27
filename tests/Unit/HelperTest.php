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
            'singular'                 => ['flight', 1, 'flight', null, false],
            'plural'                   => ['flights', 2, 'flight', null, false],
            'zero is plural'           => ['flights', 0, 'flight', null, false],
            'singular with number'     => ['1 flight', 1, 'flight', null, true],
            'plural with number'       => ['42 flights', 42, 'flight', null, true],
            'custom plural'            => ['children', 3, 'child', 'children', false],
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
            'utc'            => ['GMT+00:00', 0],
            'positive whole' => ['GMT+02:00', 2],
            'negative whole' => ['GMT-05:00', -5],
            'half hour'      => ['GMT+05:30', 5.5],
            'negative half'  => ['GMT-03:30', -3.5],
            'quarter'        => ['GMT+05:45', 5.75],
        ];
    }

    #[DataProvider('escapeProvider')]
    public function testEscapeHtml(string $expected, int|float|string|null $value): void
    {
        self::assertSame($expected, Helper::escapeHtml($value));
    }

    /**
     * @return array<string, array{0: string, 1: int|float|string|null}>
     */
    public static function escapeProvider(): array
    {
        return [
            'tags'   => ['&lt;script&gt;', '<script>'],
            'quotes' => ['&quot;x&quot;', '"x"'],
            'amp'    => ['a &amp; b', 'a & b'],
            'null'   => ['', null],
            'int'    => ['5', 5],
            'plain'  => ['Montreal (YUL)', 'Montreal (YUL)'],
        ];
    }
}
