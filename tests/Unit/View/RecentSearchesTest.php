<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Http\Input;
use TripBuilder\View\RecentSearches;

/**
 * The recent-search cookie, which is written by the browser and therefore is
 * input like any other.
 *
 * It holds SearchUrl paths, so SearchUrl::parse() is the validator -- the same
 * parser the router trusts, rather than a second regex that has to be kept in
 * step with the first.
 */
final class RecentSearchesTest extends TestCase
{
    private const string ONE_WAY = '/search/YUL151026LHRY1';
    private const string ROUND_TRIP = '/search/YUL151026LHR221026Y1';

    protected function setUp(): void
    {
        // The paths carry airport codes and cabin letters the config names.
        new Config('common');
    }

    /** @param list<mixed>|string $value */
    private static function cookie(array|string $value): Input
    {
        return new Input([
            RecentSearches::COOKIE => is_string($value) ? $value : (string) json_encode($value),
        ]);
    }

    public function testAWellFormedCookieReadsBackNewestFirst(): void
    {
        $searches = RecentSearches::read(self::cookie([self::ROUND_TRIP, self::ONE_WAY]));

        self::assertSame(
            [self::ROUND_TRIP, self::ONE_WAY],
            array_map(static fn(object $s): string => $s->path(), $searches),
        );
    }

    public function testAHandEditedCookieIsDroppedRatherThanTrusted(): void
    {
        // Every one of these has to survive being read, because any of them can
        // arrive: the cookie is whatever the browser sends.
        self::assertSame([], RecentSearches::read(self::cookie('not json at all')));
        self::assertSame([], RecentSearches::read(self::cookie('"a string, not a list"')));
        self::assertSame([], RecentSearches::read(self::cookie([])));
        self::assertSame([], RecentSearches::read(new Input()));

        // A list whose entries are not paths, or are paths that do not parse.
        self::assertSame([], RecentSearches::read(self::cookie([
            ['nested'],
            42,
            null,
            '/search/NOPE',
            '/my/bookings/1',
            '../../etc/passwd',
            '/search/YUL999999LHRY1',
        ])));
    }

    public function testTheGoodEntriesSurviveTheBadOnes(): void
    {
        // A single unparseable entry must not cost the whole history.
        $searches = RecentSearches::read(self::cookie(['/search/NOPE', self::ONE_WAY, 99]));

        self::assertCount(1, $searches);
        self::assertSame(self::ONE_WAY, $searches[0]->path());
    }

    public function testTheListIsCappedSoALongCookieCannotDrawALongMenu(): void
    {
        $many = array_map(
            static fn(int $day): string => sprintf('/search/YUL%02d1026LHRY1', $day),
            range(10, 25),
        );

        self::assertCount(6, RecentSearches::read(self::cookie($many)));
    }

    public function testRunningASearchAgainMovesItUpRatherThanDuplicatingIt(): void
    {
        $stored = [self::ONE_WAY, self::ROUND_TRIP];

        self::assertSame(
            [self::ROUND_TRIP, self::ONE_WAY],
            RecentSearches::withNewest($stored, self::ROUND_TRIP),
        );
    }

    public function testTheOldestFallsOffTheEnd(): void
    {
        $stored = array_map(
            static fn(int $day): string => sprintf('/search/YUL%02d1026LHRY1', $day),
            range(10, 15),
        );

        $next = RecentSearches::withNewest($stored, self::ROUND_TRIP);

        self::assertCount(6, $next);
        self::assertSame(self::ROUND_TRIP, $next[0]);
        self::assertNotContains('/search/YUL151026LHRY1', $next);
    }

    public function testARowIsNamedByCityAndReadsAsATrip(): void
    {
        $rows = RecentSearches::rows(self::cookie([self::ROUND_TRIP]), [
            ['code' => 'YUL', 'label' => 'Pierre Elliott Trudeau', 'city' => 'Montreal'],
            ['code' => 'LHR', 'label' => 'Heathrow', 'city' => 'London'],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('Montreal', $rows[0]['from']);
        self::assertSame('London', $rows[0]['to']);
        self::assertSame(self::ROUND_TRIP, $rows[0]['path']);
    }

    public function testARowCarriesTheSearchInPiecesSoTheFormCanBeFilled(): void
    {
        // Choosing a past search fills the form rather than running it, and the
        // browser has no parser for a search path -- SearchUrl is the only
        // definition of that grammar. So the row hands over the parts.
        $rows = RecentSearches::rows(self::cookie(['/search/YYZ121026x3CDG201026x2C321']), []);

        self::assertSame([
            'from' => 'YYZ',
            'to' => 'CDG',
            'depart' => '2026-10-12',
            'return' => '2026-10-20',
            'depart_span' => 3,
            'return_span' => 2,
            'cabin' => 'business',
            'adults' => 3,
            'children' => 2,
            'infants' => 1,
        ], $rows[0]['parts']);
    }

    public function testAOneWayCarriesAnEmptyReturnRatherThanNothing(): void
    {
        // The template writes every part into an attribute, and a null there
        // would render the word "null" into the form's return field.
        $parts = RecentSearches::rows(self::cookie([self::ONE_WAY]), [])[0]['parts'];

        self::assertSame('', $parts['return']);
        self::assertSame(1, $parts['return_span']);
    }

    public function testACodeTheNetworkNoLongerNamesStillDrawsARow(): void
    {
        // An airport disabled since the search was run. Showing the code is
        // worse than showing a name and better than showing a blank row.
        $rows = RecentSearches::rows(self::cookie([self::ONE_WAY]), []);

        self::assertSame('YUL', $rows[0]['from']);
        self::assertSame('LHR', $rows[0]['to']);
    }

    public function testTheDatesReadTheWaySomeoneWouldSayThem(): void
    {
        $within = RecentSearches::rows(self::cookie(['/search/YUL151026LHR221026Y1']), []);
        $across = RecentSearches::rows(self::cookie(['/search/YUL281026LHR031126Y1']), []);
        $oneWay = RecentSearches::rows(self::cookie([self::ONE_WAY]), []);

        // The month is said once when both dates share it, and twice when not.
        self::assertSame('15 – 22 Oct', $within[0]['when']);
        self::assertSame('28 Oct – 3 Nov', $across[0]['when']);
        self::assertSame('15 Oct', $oneWay[0]['when']);
    }
}
