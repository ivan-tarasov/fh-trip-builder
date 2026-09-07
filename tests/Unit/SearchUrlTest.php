<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Http\Input;
use TripBuilder\SearchUrl;
use TripBuilder\TripType;

final class SearchUrlTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    private static function at(string $path): ?SearchUrl
    {
        return SearchUrl::parse($path);
    }

    public function testReadsAOneWaySearch(): void
    {
        $url = self::at('/search/YUL160926LHRY1');

        self::assertSame('YUL', $url->from);
        self::assertSame('LHR', $url->to);
        self::assertSame('2026-09-16', $url->depart);
        self::assertNull($url->return);
        self::assertSame(CabinClass::Economy, $url->cabin);
        self::assertSame(TripType::Oneway, $url->tripType());
    }

    public function testAReturnDateIsWhatMakesItARoundTrip(): void
    {
        $url = self::at('/search/YUL160926LHR300926C1');

        self::assertSame('2026-09-30', $url->return);
        self::assertSame(TripType::Roundtrip, $url->tripType());
        self::assertSame(CabinClass::Business, $url->cabin);
    }

    public function testReadsThePassengerBlock(): void
    {
        $url = self::at('/search/YUL160926LHR300926W421');

        self::assertSame([4, 2, 1], [$url->adults, $url->children, $url->infants]);
        self::assertSame(CabinClass::PremiumEconomy, $url->cabin);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function roundTrips(): array
    {
        return [
            ['/search/YUL160926LHRY1'],
            ['/search/YUL160926LHR300926C1'],
            ['/search/YUL160926LHR300926W421'],
            ['/search/YUL160926LHR300926F42'],
            ['/search/A39160926A39300926W1'],
        ];
    }

    #[DataProvider('roundTrips')]
    public function testAPathSurvivesBeingParsedAndWrittenBack(string $path): void
    {
        self::assertSame($path, self::at($path)->path());
    }

    public function testAYearIsReadLiterally(): void
    {
        // No "next occurrence" rule to get wrong: a date in the past is read as
        // the date in the past, and simply finds nothing.
        self::assertSame('2025-09-16', self::at('/search/YUL160925LHRY1')->depart);
        self::assertSame('2031-01-05', self::at('/search/YUL050131LHRY1')->depart);
    }

    public function testARealCalendarIsRequired(): void
    {
        // 2026 is not a leap year; 2028 is.
        self::assertNull(self::at('/search/YUL290226LHRY1'));
        self::assertSame('2028-02-29', self::at('/search/YUL290228LHRY1')->depart);
    }

    public function testAnAirportCodeCarryingADigitIsStillUnambiguous(): void
    {
        // A39 (Phoenix Regional) is a real, enabled row. Matching [A-Z]{3}
        // would make it unaddressable, and without the mandatory cabin letter
        // these two would not be tellable apart.
        $roundtrip = self::at('/search/A39160926A39300926W1');
        $oneway = self::at('/search/A39160926A39W1');

        self::assertSame('2026-09-30', $roundtrip->return);
        self::assertNull($oneway->return);
        self::assertSame('A39', $oneway->from);
        self::assertSame('A39', $oneway->to);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function notSearchPaths(): array
    {
        return [
            ['/search/YUL160926LHR'],        // no cabin, no passengers
            ['/search/YUL160926LHRX1'],      // X is not a cabin
            ['/search/YU160926LHRY1'],       // two-letter code
            ['/search/YUL16092LHRY1'],       // three-digit date
            ['/search/YUL160926LHRY1234'],   // four passenger digits
            ['/search/YUL160926LHRY1junk'],  // trailing junk
            ['/search/YUL320226LHRY1'],      // 32 February
            ['/search/yul160926lhry1'],      // lowercase
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

        self::assertSame('/search/YUL160926LHR300926C1', $url->path());
    }

    public function testAReturnDateAloneMakesItARoundTrip(): void
    {
        // The form states no trip type any more -- an empty return field is how
        // it says one-way, which is what let the tabs go. Reading an absent
        // `triptype` as one-way threw the return date away on the way in, and
        // every round trip submitted came back as a one-way search.
        $url = SearchUrl::fromQuery(new Input([
            'from' => 'YUL',
            'to' => 'LHR',
            'depart' => '2026-09-16',
            'return' => '2026-09-30',
        ]));

        self::assertNotNull($url);
        self::assertSame(TripType::Roundtrip, $url->tripType());
        self::assertSame('/search/YUL160926LHR300926Y1', $url->path());
    }

    public function testNoReturnDateAndNoTripTypeIsAOneWay(): void
    {
        $url = SearchUrl::fromQuery(new Input([
            'from' => 'YUL',
            'to' => 'LHR',
            'depart' => '2026-09-16',
        ]));

        self::assertNotNull($url);
        self::assertSame(TripType::Oneway, $url->tripType());
        self::assertSame('/search/YUL160926LHRY1', $url->path());
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
        self::assertSame('/search/YUL160926LHRY1', $url->path());
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

    public function testASingleDateStillSpellsItselfExactlyAsBefore(): void
    {
        // The whole reason a span of one writes nothing: every link already
        // shared has to render byte for byte as it did, or the canonical
        // redirect at SearchController::index bounces it somewhere new.
        foreach ([
            '/search/YUL151026LHRY1',
            '/search/YUL151026LHR221026Y1',
            '/search/YUL160926LHR300926C211',
        ] as $path) {
            self::assertSame($path, SearchUrl::parse($path)?->path());
        }
    }

    public function testAFlexibleDateRoundTripsThroughTheUrl(): void
    {
        $url = SearchUrl::parse('/search/YUL151026x3LHR221026x2Y1');

        self::assertNotNull($url);
        self::assertSame(3, $url->departSpan);
        self::assertSame(2, $url->returnSpan);
        self::assertSame('/search/YUL151026x3LHR221026x2Y1', $url->path());

        // The window the search will actually cover, inclusive of the named day.
        self::assertSame('2026-10-15', $url->depart);
        self::assertSame('2026-10-17', $url->departUntil());
        self::assertSame('2026-10-22', $url->return);
        self::assertSame('2026-10-23', $url->returnUntil());
    }

    public function testAnAirportCodeCarryingADigitSurvivesASpanBesideIt(): void
    {
        // A39 is a real airport. A bare digit after the date could not be told
        // from one, which is why a span is marked rather than appended.
        $url = SearchUrl::parse('/search/A39151026x2A39221026Y1');

        self::assertNotNull($url);
        self::assertSame('A39', $url->from);
        self::assertSame('A39', $url->to);
        self::assertSame(2, $url->departSpan);
        self::assertSame('/search/A39151026x2A39221026Y1', $url->path());
    }

    public function testASpanTheSearchWillNotRunIsRefused(): void
    {
        // Not clamped: the URL asked for something specific, and answering a
        // different search would look like it had worked.
        self::assertNull(SearchUrl::parse('/search/YUL151026x9LHRY1'));
        self::assertNull(SearchUrl::parse('/search/YUL151026x4LHRY1'));
        // A span of one is spelled by writing nothing, so `x1` is not a path.
        self::assertNull(SearchUrl::parse('/search/YUL151026x1LHRY1'));
        self::assertNull(SearchUrl::parse('/search/YUL151026xLHRY1'));
    }

    public function testATripCannotComeBackBeforeItLeaves(): void
    {
        // Unchecked until flexible dates: this exact path answered 200 and drew
        // a results page.
        self::assertNull(SearchUrl::parse('/search/YUL151026LHR011025Y1'));
        self::assertNull(SearchUrl::fromQuery(new Input([
            'from' => 'YUL',
            'to' => 'LHR',
            'depart' => '2026-10-15',
            'return' => '2025-10-01',
        ])));

        // Leaving and returning the same day is a real, if short, trip.
        self::assertNotNull(SearchUrl::parse('/search/YUL151026LHR151026Y1'));
    }

    public function testTheFormsFlexFieldsReachTheUrl(): void
    {
        $url = SearchUrl::fromQuery(new Input([
            'from' => 'YUL',
            'to' => 'LHR',
            'depart' => '2026-10-15',
            'return' => '2026-10-22',
            'depart_flex' => '3',
            'return_flex' => '2',
        ]));

        self::assertSame('/search/YUL151026x3LHR221026x2Y1', $url?->path());
    }

    public function testAFlexTheSearchWillNotRunBecomesNoFlex(): void
    {
        // Fewer days than asked for, never more.
        $url = SearchUrl::fromQuery(new Input([
            'from' => 'YUL',
            'to' => 'LHR',
            'depart' => '2026-10-15',
            'depart_flex' => '9',
        ]));

        self::assertNotNull($url);
        self::assertSame(1, $url->departSpan);
        self::assertSame('/search/YUL151026LHRY1', $url->path());
    }

    public function testAOneWayCarriesNoReturnSpan(): void
    {
        $url = SearchUrl::fromQuery(new Input([
            'from' => 'YUL',
            'to' => 'LHR',
            'depart' => '2026-10-15',
            'return_flex' => '3',
        ]));

        self::assertNotNull($url);
        self::assertSame(1, $url->returnSpan);
        self::assertNull($url->returnUntil());
    }
}
