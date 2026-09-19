<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\CityImageRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * `CityImageRepository` -- the cache `Noah\Cities\Images` fills from
 * Wikipedia and C10 (#395)'s "Travel deals" carousel reads. The network
 * call and the S3 upload are deliberately untested, the same reason
 * `Noah\Currency\Rates`'s own `fetch()` is not: CI has curl but no
 * promise of a route to the internet, and a test that could only pass by
 * calling out would be a flaky one waiting to happen. What is tested here
 * is the cache's own contract, which owes nothing to Wikipedia or S3
 * being up.
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

    public function testAnUnknownCityHasNeverBeenLookedUp(): void
    {
        $images = new CityImageRepository($this->connection());

        self::assertNull($images->find(self::CITY));
        self::assertNull($images->imageKeyFor(self::CITY));
    }

    public function testStoringARealAnswerMakesItReadable(): void
    {
        $images = new CityImageRepository($this->connection());

        $images->store(self::CITY, 'images/cities/ZZQ.jpg', 'https://example.com/photo.jpg', 'A fictional city.');

        $row = $images->find(self::CITY);

        self::assertNotNull($row);
        self::assertSame('images/cities/ZZQ.jpg', $row['image_key']);
        self::assertSame('https://example.com/photo.jpg', $row['image_source_url']);
        self::assertSame('A fictional city.', $row['extract']);
        self::assertSame('images/cities/ZZQ.jpg', $images->imageKeyFor(self::CITY));
        self::assertSame('A fictional city.', $images->extractFor(self::CITY));
    }

    /**
     * The other real answer: Wikipedia was asked and had nothing. `find()`
     * must still return a row -- that is the whole point of recording a
     * null, the same "recorded even when nothing was found" idiom
     * `RoutePriceRepository::build()` already uses -- so the next run does
     * not spend a request re-asking a city that has already answered.
     */
    public function testStoringNothingRecordsAConfirmedNoAnswer(): void
    {
        $images = new CityImageRepository($this->connection());

        $images->store(self::CITY, null, null, null);

        $row = $images->find(self::CITY);

        self::assertNotNull($row);
        self::assertNull($row['image_key']);
        self::assertNull($images->imageKeyFor(self::CITY));
        self::assertNull($images->extractFor(self::CITY));
    }

    public function testStoringAgainReplacesRatherThanFailing(): void
    {
        $images = new CityImageRepository($this->connection());

        $images->store(self::CITY, null, null, null);
        $images->store(self::CITY, 'images/cities/ZZQ.jpg', 'https://example.com/found-one-later.jpg', 'Found later.');

        self::assertSame('images/cities/ZZQ.jpg', $images->imageKeyFor(self::CITY));
    }

    public function testStaleOrMissingFindsBothAnUnknownCityAndAnOldOne(): void
    {
        $images = new CityImageRepository($this->connection());

        $images->store(self::CITY, 'images/cities/ZZQ.jpg', 'https://example.com/photo.jpg', null);
        $this->connection()->execute(
            'UPDATE city_images SET fetched_at = NOW() - INTERVAL 100 DAY WHERE city_code = ?',
            [self::CITY],
        );

        self::assertSame(
            ['ZZZ', self::CITY],
            $images->staleOrMissing(['ZZZ', self::CITY], staleAfterDays: 90),
        );
    }

    public function testStaleOrMissingLeavesOutAFreshRow(): void
    {
        $images = new CityImageRepository($this->connection());

        $images->store(self::CITY, 'images/cities/ZZQ.jpg', 'https://example.com/photo.jpg', null);

        self::assertSame([], $images->staleOrMissing([self::CITY], staleAfterDays: 90));
    }
}
