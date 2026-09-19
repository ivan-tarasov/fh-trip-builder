<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\CityImageRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * `CityImageRepository` -- the cache `Noah\Cities\Images` fills from
 * Wikipedia and C10 (#395)'s "Travel deals" carousel reads. The network
 * call itself is deliberately untested, the same reason
 * `Noah\Currency\Rates`'s own `fetch()` is not: CI has curl but no
 * promise of a route to the internet, and a test that could only pass by
 * calling out would be a flaky one waiting to happen. What is tested here
 * is the cache's own contract, which owes nothing to Wikipedia being up.
 */
final class CityImageRepositoryTest extends IntegrationTestCase
{
    private const string CITY = 'ZZQ';

    protected function tearDown(): void
    {
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM city_images WHERE city_code = ?', [self::CITY]);
    }

    public function testAnUnknownCityHasNoRowAndNoImage(): void
    {
        $images = new CityImageRepository($this->connection());

        self::assertFalse($images->has(self::CITY));
        self::assertNull($images->imageFor(self::CITY));
    }

    public function testStoringARealUrlMakesItKnownAndReadable(): void
    {
        $images = new CityImageRepository($this->connection());

        $images->store(self::CITY, 'https://example.com/photo.jpg');

        self::assertTrue($images->has(self::CITY));
        self::assertSame('https://example.com/photo.jpg', $images->imageFor(self::CITY));
    }

    /**
     * The other real answer: Wikipedia was asked and had nothing. `has()`
     * must still say true -- that is the whole point of recording a null,
     * the same "recorded even when nothing was found" idiom
     * `RoutePriceRepository::build()` already uses -- so the next run does
     * not spend a request re-asking a city that has already answered.
     */
    public function testStoringNullRecordsAConfirmedNoPhotoAnswer(): void
    {
        $images = new CityImageRepository($this->connection());

        $images->store(self::CITY, null);

        self::assertTrue($images->has(self::CITY));
        self::assertNull($images->imageFor(self::CITY));
    }

    public function testStoringAgainReplacesRatherThanFailing(): void
    {
        $images = new CityImageRepository($this->connection());

        $images->store(self::CITY, null);
        $images->store(self::CITY, 'https://example.com/found-one-later.jpg');

        self::assertSame('https://example.com/found-one-later.jpg', $images->imageFor(self::CITY));
    }
}
