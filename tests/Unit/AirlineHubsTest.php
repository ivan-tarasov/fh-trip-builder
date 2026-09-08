<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Repository\AirlineRepository;

/**
 * The airports an airline is based at, read out of one column.
 *
 * `airlines.hubs` is a space-separated list -- "YYZ YUL YVR" -- so the split is
 * the only piece of the airline page that is logic rather than SQL, and it is
 * the piece a stray space breaks. An empty code surviving the split reaches
 * AirportRepository::byCodes() as a placeholder matching nothing, which does
 * not fail: it quietly returns one fewer hub than the page counted, so the
 * facts tile says 3 and the map draws 2.
 */
final class AirlineHubsTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function hubs(): array
    {
        return [
            'one hub' => ['DXB', ['DXB']],
            'three, as Air Canada has' => ['YYZ YUL YVR', ['YYZ', 'YUL', 'YVR']],
            // easyJet's, the longest in the seed, in the order the column
            // holds it -- which is not alphabetical and is kept as written.
            'eight, in the seed\'s own order' => [
                'LGW STN MAN EDI CDG MXP AMS BCN',
                ['LGW', 'STN', 'MAN', 'EDI', 'CDG', 'MXP', 'AMS', 'BCN'],
            ],
            // None of these is in the seed today, and all of them are one
            // careless edit away from being.
            'a trailing space' => ['DXB ', ['DXB']],
            'a leading space' => [' DXB', ['DXB']],
            'a double space' => ['YYZ  YUL', ['YYZ', 'YUL']],
            'a tab' => ["YYZ\tYUL", ['YYZ', 'YUL']],
            'a newline' => ["YYZ\nYUL", ['YYZ', 'YUL']],
            'nothing at all' => ['', []],
            'nothing but space' => ['   ', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('hubs')]
    public function testTheColumnSplitsIntoCodes(string $column, array $expected): void
    {
        self::assertSame($expected, AirlineRepository::hubCodes($column));
    }

    /**
     * The list is returned as a list, because the caller counts it.
     *
     * A filter that preserved keys would leave gaps, and count() would still be
     * right while the JSON and the foreach were not -- the sort of thing that
     * shows up as a hub missing from the middle of a map.
     */
    public function testTheCodesComeBackAsAList(): void
    {
        $codes = AirlineRepository::hubCodes(' YYZ  YUL YVR ');

        self::assertSame([0, 1, 2], array_keys($codes));
        self::assertCount(3, $codes);
    }
}
