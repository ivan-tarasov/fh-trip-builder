<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Horizon;
use TripBuilder\Http\Input;
use TripBuilder\SearchUrl;
use TripBuilder\TripType;

final class SearchUrlTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    /**
     * A search reaching past the last day the site has flights for is not a
     * search it can run, so it is refused the way a span past `MAX_SPAN` is --
     * null rather than a page that finds nothing, because an empty result looks
     * like the route is empty rather than like the question being out of range
     * (E30, #215).
     */
    public function testASearchPastTheHorizonIsNotASearch(): void
    {
        self::assertNotNull(SearchUrl::parse(self::at_(Horizon::DAYS)), 'the horizon itself is bookable');
        self::assertNull(SearchUrl::parse(self::at_(Horizon::DAYS + 1)));
        self::assertNull(SearchUrl::parse(self::at_(Horizon::DAYS + 200)));
    }

    /**
     * The end of the window, not its start.
     *
     * A flexible search is up to three days wide, so one beginning on the last
     * day the calendar offers reaches two days past it -- exactly the case a
     * bound on the departure date alone waves through.
     */
    public function testAFlexibleWindowCannotReachPastTheHorizonEither(): void
    {
        $lastDay = self::short(Horizon::DAYS);

        self::assertNotNull(SearchUrl::parse('/search/YUL' . $lastDay . 'LHRY1'), 'one day, on the horizon');
        self::assertNull(
            SearchUrl::parse('/search/YUL' . $lastDay . 'x3LHRY1'),
            'a three-day window opening on the horizon ends two days past it',
        );
    }

    /** And the return leg is bounded by it as well as the outbound. */
    public function testAReturnPastTheHorizonIsRefused(): void
    {
        $out = self::short(10);

        self::assertNotNull(SearchUrl::parse('/search/YUL' . $out . 'LHR' . self::short(Horizon::DAYS) . 'Y1'));
        self::assertNull(SearchUrl::parse('/search/YUL' . $out . 'LHR' . self::short(Horizon::DAYS + 1) . 'Y1'));
    }

    /** A one-way path that departs `$days` from today. */
    private static function at_(int $days): string
    {
        return '/search/YUL' . self::short($days) . 'LHRY1';
    }

    private static function short(int $days): string
    {
        return date('dmy', (int) strtotime('+' . $days . ' days'));
    }

    /**
     * A path that parses, which is what every test below is about.
     *
     * The assertion is here rather than at each of two dozen call sites: a
     * path that stops parsing should fail as "this is not a search" once,
     * rather than as two dozen reads of a property on null.
     *
     * Which is why the tests about paths that should *not* parse call the
     * parser directly. They are the ones for which null is the answer.
     */
    private static function at(string $path): SearchUrl
    {
        $url = SearchUrl::parse($path);

        self::assertInstanceOf(SearchUrl::class, $url, $path . ' should parse');

        return $url;
    }

    /** The older query-string form, which is nullable for the same reason. */
    private static function fromQuery(Input $query): SearchUrl
    {
        $url = SearchUrl::fromQuery($query);

        self::assertInstanceOf(SearchUrl::class, $url, 'the query should describe a search');

        return $url;
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

    /**
     * The two-digit year is read as itself, with no "next occurrence" rule to
     * get wrong: a date in the past is the date in the past, and finds nothing.
     *
     * The forward half used to be `050131` -- 2031 -- and cannot be now, because
     * `parse()` refuses anything past the horizon (E30, #215). A date inside it
     * makes the same point: `27` is 2027 and not 1927.
     */
    public function testAYearIsReadLiterally(): void
    {
        self::assertSame('2025-09-16', self::at('/search/YUL160925LHRY1')->depart);
        self::assertSame(
            date('Y-m-d', (int) strtotime('+30 days')),
            self::at('/search/YUL' . date('dmy', (int) strtotime('+30 days')) . 'LHRY1')->depart,
        );
    }

    /**
     * `checkdate()` and not a `DateTimeImmutable`, which rolls 31 February into
     * March without complaint and would quietly answer a different search from
     * the one the URL names.
     *
     * Shown with a month that has no 31st rather than with a leap day: the next
     * 29 February is 2028, which is past the horizon and so refused for a
     * different reason, and a test that passes for the wrong reason is worse
     * than no test.
     */
    public function testARealCalendarIsRequired(): void
    {
        self::assertNull(SearchUrl::parse('/search/YUL290226LHRY1'), '2026 is not a leap year');
        self::assertNull(SearchUrl::parse('/search/YUL310227LHRY1'), 'February has no 31st');
        self::assertNotNull(SearchUrl::parse('/search/YUL280227LHRY1'), 'but it does have a 28th');
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
        self::assertNull(SearchUrl::parse($path));
    }

    public function testReadsTheOlderQueryStringForm(): void
    {
        $url = self::fromQuery(new Input([
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
        $url = self::fromQuery(new Input([
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
        $url = self::fromQuery(new Input([
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
        $url = self::fromQuery(new Input([
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
        $url = self::fromQuery(new Input([
            'from' => 'YUL',
            'to' => 'LHR',
            'depart' => '2026-10-15',
            'return' => '2026-10-22',
            'depart_flex' => '3',
            'return_flex' => '2',
        ]));

        self::assertSame('/search/YUL151026x3LHR221026x2Y1', $url->path());
    }

    public function testAFlexTheSearchWillNotRunBecomesNoFlex(): void
    {
        // Fewer days than asked for, never more.
        $url = self::fromQuery(new Input([
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
        $url = self::fromQuery(new Input([
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
