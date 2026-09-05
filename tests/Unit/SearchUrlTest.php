<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Http\Input;
use TripBuilder\SearchUrl;
use TripBuilder\TripType;

final class SearchUrlTest extends TestCase
{
    private const string NOW = '2026-09-05';

    protected function setUp(): void
    {
        new Config('common');
    }

    private static function at(string $path): ?SearchUrl
    {
        return SearchUrl::parse($path, new DateTimeImmutable(self::NOW));
    }

    public function testReadsAOneWaySearch(): void
    {
        $url = self::at('/search/YUL1609LHRY1');

        self::assertSame('YUL', $url->from);
        self::assertSame('LHR', $url->to);
        self::assertSame('2026-09-16', $url->depart);
        self::assertNull($url->return);
        self::assertSame(CabinClass::Economy, $url->cabin);
        self::assertSame(TripType::Oneway, $url->tripType());
    }

    public function testAReturnDateIsWhatMakesItARoundTrip(): void
    {
        $url = self::at('/search/YUL1609LHR3009C1');

        self::assertSame('2026-09-30', $url->return);
        self::assertSame(TripType::Roundtrip, $url->tripType());
        self::assertSame(CabinClass::Business, $url->cabin);
    }

    public function testReadsThePassengerBlock(): void
    {
        $url = self::at('/search/YUL1609LHR3009W421');

        self::assertSame([4, 2, 1], [$url->adults, $url->children, $url->infants]);
        self::assertSame(CabinClass::PremiumEconomy, $url->cabin);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function roundTrips(): array
    {
        return [
            ['/search/YUL1609LHRY1'],
            ['/search/YUL1609LHR3009C1'],
            ['/search/YUL1609LHR3009W421'],
            ['/search/YUL1609LHR3009F42'],
            ['/search/A391609A393009W1'],
        ];
    }

    #[DataProvider('roundTrips')]
    public function testAPathSurvivesBeingParsedAndWrittenBack(string $path): void
    {
        self::assertSame($path, self::at($path)->path());
    }

    public function testAnAirportCodeCarryingADigitIsStillUnambiguous(): void
    {
        // A39 (Phoenix Regional) is a real, enabled row. Matching [A-Z]{3}
        // would make it unaddressable, and without the mandatory cabin letter
        // these two would not be tellable apart.
        $roundtrip = self::at('/search/A391609A393009W1');
        $oneway = self::at('/search/A391609A39W1');

        self::assertSame('2026-09-30', $roundtrip->return);
        self::assertNull($oneway->return);
        self::assertSame('A39', $oneway->from);
        self::assertSame('A39', $oneway->to);
    }

    public function testADateAlreadyPastRollsToNextYear(): void
    {
        // 1 February, read on 5 September, means the coming February.
        self::assertSame('2027-02-01', self::at('/search/YUL0102LHRY1')->depart);
    }

    public function testAReturnBeforeItsDepartureRollsAYear(): void
    {
        $url = self::at('/search/YUL3012LHR0501Y1');

        self::assertSame('2026-12-30', $url->depart);
        self::assertSame('2027-01-05', $url->return);
    }

    public function testTwentyNinthOfFebruaryFindsALeapYear(): void
    {
        self::assertSame('2028-02-29', self::at('/search/YUL2902LHRY1')->depart);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function notSearchPaths(): array
    {
        return [
            ['/search/YUL1609LHR'],        // no cabin, no passengers
            ['/search/YUL1609LHRX1'],      // X is not a cabin
            ['/search/YU1609LHRY1'],       // two-letter code
            ['/search/YUL160LHRY1'],       // three-digit date
            ['/search/YUL1609LHRY1234'],   // four passenger digits
            ['/search/YUL1609LHRY1junk'],  // trailing junk
            ['/search/YUL3202LHRY1'],      // 32 February
            ['/search/yul1609lhry1'],      // lowercase
            ['/search/'],
            ['/my/bookings/100001'],
        ];
    }

    #[DataProvider('notSearchPaths')]
    public function testAnythingElseIsNotASearchPath(string $path): void
    {
        // Null, not a half-built object: the caller falls through to the older
        // query-string form rather than searching for something nobody asked for.
        self::assertNull(self::at($path));
    }

    public function testReadsTheOlderQueryStringForm(): void
    {
        $url = SearchUrl::fromQuery(new Input([
            'from' => 'yul',
            'to' => 'lhr',
            'depart' => '2026-09-16',
            'return' => '2026-09-30',
            'triptype' => 'roundtrip',
            'class' => 'business',
        ]));

        self::assertSame('/search/YUL1609LHR3009C1', $url->path());
    }

    public function testAnExplicitOneWayDropsAStaleReturnDate(): void
    {
        // The form leaves the return date in place when the tab is switched
        // back, so the trip type has to win.
        $url = SearchUrl::fromQuery(new Input([
            'from' => 'YUL',
            'to' => 'LHR',
            'depart' => '2026-09-16',
            'return' => '2026-09-30',
            'triptype' => 'oneway',
        ]));

        self::assertNull($url->return);
        self::assertSame('/search/YUL1609LHRY1', $url->path());
    }

    /**
     * @return list<array{0: array<string, string>}>
     */
    public static function unusableQueries(): array
    {
        return [
            [[]],
            [['from' => 'YUL', 'to' => 'LHR']],
            [['from' => 'YUL', 'depart' => '2026-09-16']],
            [['from' => 'Y', 'to' => 'LHR', 'depart' => '2026-09-16']],
            [['from' => 'YUL', 'to' => 'LHR', 'depart' => 'tomorrow']],
            [['from' => 'YUL', 'to' => 'LHR', 'depart' => '2026-02-31']],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('unusableQueries')]
    public function testAQueryThatNamesNoSearchIsRejected(array $query): void
    {
        self::assertNull(SearchUrl::fromQuery(new Input($query)));
    }
}
