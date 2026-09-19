<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\CountryContentRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * `CountryContentRepository` -- the cache `Noah\Countries\Content` fills
 * from Wikipedia and C17 (#409)'s country page reads. The network call
 * is deliberately untested, the same reason `CityImageRepositoryTest`
 * does not test `Noah\Cities\Images`'s own call: CI has curl but no
 * promise of a route to the internet, and a test that could only pass by
 * calling out would be a flaky one waiting to happen. What is tested here
 * is the cache's own contract, which owes nothing to Wikipedia being up.
 */
final class CountryContentRepositoryTest extends IntegrationTestCase
{
    private const string COUNTRY = 'ZZ';

    protected function tearDown(): void
    {
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM country_content WHERE country_code = ?', [self::COUNTRY]);
    }

    public function testAnUnknownCountryHasNeverBeenLookedUp(): void
    {
        $content = new CountryContentRepository($this->connection());

        self::assertNull($content->find(self::COUNTRY));
        self::assertNull($content->extractFor(self::COUNTRY));
    }

    public function testStoringARealAnswerMakesItReadable(): void
    {
        $content = new CountryContentRepository($this->connection());

        $content->store(self::COUNTRY, 'A fictional country.');

        $row = $content->find(self::COUNTRY);

        self::assertNotNull($row);
        self::assertSame('A fictional country.', $row['extract']);
        self::assertSame('A fictional country.', $content->extractFor(self::COUNTRY));
    }

    /**
     * The other real answer: Wikipedia was asked and had nothing. `find()`
     * must still return a row -- the whole point of recording a null, the
     * same "recorded even when nothing was found" idiom
     * `CityImageRepository` already uses -- so the next run does not
     * spend a request re-asking a country that has already answered.
     */
    public function testStoringNothingRecordsAConfirmedNoAnswer(): void
    {
        $content = new CountryContentRepository($this->connection());

        $content->store(self::COUNTRY, null);

        $row = $content->find(self::COUNTRY);

        self::assertNotNull($row);
        self::assertNull($row['extract']);
        self::assertNull($content->extractFor(self::COUNTRY));
    }

    public function testStoringAgainReplacesRatherThanFailing(): void
    {
        $content = new CountryContentRepository($this->connection());

        $content->store(self::COUNTRY, null);
        $content->store(self::COUNTRY, 'Found later.');

        self::assertSame('Found later.', $content->extractFor(self::COUNTRY));
    }

    public function testStaleOrMissingFindsBothAnUnknownCountryAndAnOldOne(): void
    {
        $content = new CountryContentRepository($this->connection());

        $content->store(self::COUNTRY, 'A fictional country.');
        $this->connection()->execute(
            'UPDATE country_content SET fetched_at = NOW() - INTERVAL 100 DAY WHERE country_code = ?',
            [self::COUNTRY],
        );

        self::assertSame(
            ['XX', self::COUNTRY],
            $content->staleOrMissing(['XX', self::COUNTRY], staleAfterDays: 90),
        );
    }

    public function testStaleOrMissingLeavesOutAFreshRow(): void
    {
        $content = new CountryContentRepository($this->connection());

        $content->store(self::COUNTRY, 'A fictional country.');

        self::assertSame([], $content->staleOrMissing([self::COUNTRY], staleAfterDays: 90));
    }
}
