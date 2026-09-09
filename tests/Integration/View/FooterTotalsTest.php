<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\View;

use TripBuilder\Config;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\CountryRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\View\LayoutData;

/**
 * The footer says how many are behind each "All ..." link.
 *
 * There are two ways for that number to start lying, and neither raises an
 * error -- the footer simply states something untrue. A count can drift from
 * the listing it describes, and a column can be wired to somebody else's
 * count. One test each.
 */
final class FooterTotalsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    /**
     * Each count returns exactly what the page behind the link lists.
     *
     * Count and listing are two queries sharing one filter. This is the guard
     * against the day only one of them is changed.
     */
    public function testEachCountMatchesTheListingItDescribes(): void
    {
        foreach ($this->directories() as $name => $directory) {
            self::assertSame(
                $directory['listed'],
                $directory['counted'],
                $name . ': the count and the page disagree',
            );
        }
    }

    /**
     * And each column is shown its own total rather than a neighbour's.
     *
     * Only detectable while the four are different numbers, so that is
     * asserted too -- otherwise this quietly stops being a test.
     */
    public function testEachColumnShowsItsOwnTotal(): void
    {
        $expected = array_map(
            static fn(array $directory): int => $directory['listed'],
            $this->directories(),
        );

        self::assertSameSize($expected, array_unique($expected), 'two directories are the same size');

        $layout = new LayoutData();
        $seen = [];

        foreach (Config::get('site.footer-columns') as $column) {
            $key = $column['more']['total'] ?? null;

            if ($key === null) {
                continue;
            }

            self::assertArrayHasKey($key, $expected, $key . ' is not a directory this test knows about');

            $text = $layout->footerMore($column['more'])['text'];

            self::assertSame(
                1,
                preg_match('/\d[\d,]*/', $text, $match),
                $key . ': "' . $text . '" carries no count',
            );

            $seen[$key] = (int) str_replace(',', '', $match[0]);
        }

        ksort($expected);
        ksort($seen);

        self::assertSame($expected, $seen);
    }

    /**
     * @return array<string, array{listed: int, counted: int}>
     */
    private function directories(): array
    {
        $connection = $this->connection();

        return [
            'countries' => [
                'listed' => count(new CountryRepository($connection)->sellable()),
                'counted' => new CountryRepository($connection)->countSellable(),
            ],
            'cities' => [
                'listed' => count(new CityRepository($connection)->all()),
                'counted' => new CityRepository($connection)->countAll(),
            ],
            'airports' => [
                'listed' => count(new AirportRepository($connection)->enabled(true)),
                'counted' => new AirportRepository($connection)->countEnabled(true),
            ],
            'airlines' => [
                'listed' => count(new AirlineRepository($connection)->sellable()),
                'counted' => new AirlineRepository($connection)->countSellable(),
            ],
        ];
    }
}
