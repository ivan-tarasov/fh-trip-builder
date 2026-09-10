<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use TripBuilder\Config;
use TripBuilder\View\ItineraryPresenter;
use TripBuilder\View\StoredItinerary;

/**
 * The presenter both flight lists draw their cards through.
 *
 * The search results and the saved-flights page share it, and
 * `BookingPresenter` holds an instance of it, so what it decides reaches every
 * price, duration, layover chip and warning in the app. None of it was tested.
 *
 * What is pinned here is the rules, not the getters: the thresholds a note
 * turns on at, the deduplication, the three-way country comparison behind the
 * visa check, and the cabin map -- each one a decision that would break without
 * anything failing. The date and airport fields are passed straight through and
 * are left to the render tests that already read them off a page.
 *
 * Fixtures go through `StoredItinerary::fromJson()` rather than being built as
 * `stdClass` by hand. That is the saved-flights page's own path and it derives
 * `layovers`, `stops` and `total_duration` from the segment stamps, so a wait
 * in one of these tests follows from the times either side of it, the way it
 * does in production. Nothing here needs a database or a currency:
 * `direction()` never reaches for a price.
 */
final class ItineraryPresenterTest extends TestCase
{
    private const DAY = '2026-09-15';
    private const NEXT_DAY = '2026-09-16';

    /**
     * Where each airport in these fixtures is.
     *
     * Here because the visa notice compares countries and the first draft of
     * these fixtures had a Montreal-Toronto-Vancouver trip whose legs each
     * "arrived in the United Kingdom" -- coherent enough to pass, and useless
     * for reading. A fixture that lies about itself is the thing these tests
     * exist to catch.
     */
    private const array COUNTRY_OF = [
        'YUL' => 'Canada',
        'YYZ' => 'Canada',
        'YVR' => 'Canada',
        'YYC' => 'Canada',
        'LHR' => 'United Kingdom',
        'CDG' => 'France',
    ];

    protected function setUp(): void
    {
        // The night window and the CDN host both come from config.
        new Config('common');
    }

    /*
    |--------------------------------------------------------------------------
    | The cabin map
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{string, string}> */
    public static function cabinCodes(): iterable
    {
        yield 'economy' => ['Y', 'Economy'];
        yield 'premium economy' => ['W', 'Premium economy'];
        yield 'business' => ['C', 'Business'];
        yield 'the other business code' => ['J', 'Business'];
        yield 'first' => ['F', 'First'];
    }

    #[DataProvider('cabinCodes')]
    public function testACabinCodeBecomesItsName(string $code, string $name): void
    {
        $segments = $this->direction($this->itinerary([
            $this->segment(cabin: $code),
        ]))['segments'];

        self::assertSame($name, $segments[0]['cabin']);
    }

    /**
     * And anything else shows no cabin rather than a made-up one.
     *
     * This is the rule the class docblock states, and until now nothing held
     * it. A code that does not resolve is bad data; printing `ucfirst()` of it,
     * or defaulting to economy, would put a cabin on the card that nobody sold.
     */
    public function testAnUnknownCabinCodeShowsNothing(): void
    {
        foreach (['X', 'ZZ', '', 'y'] as $code) {
            $segments = $this->direction($this->itinerary([$this->segment(cabin: $code)]))['segments'];

            self::assertNull($segments[0]['cabin'], sprintf('%s is not a cabin this app sells', var_export($code, true)));
        }
    }

    public function testASegmentWithNoCabinAtAllShowsNothing(): void
    {
        $segment = $this->segment();
        unset($segment['cabin_code']);

        $segments = $this->direction($this->itinerary([$segment]))['segments'];

        self::assertNull($segments[0]['cabin']);
    }

    /*
    |--------------------------------------------------------------------------
    | Layover notes, and the thresholds they turn on at
    |--------------------------------------------------------------------------
    */

    public function testAShortWaitIsATightConnection(): void
    {
        $notes = $this->layoverNotes('10:00', '11:00');

        self::assertSame([['text' => 'Tight connection', 'tone' => 'risk']], $notes);
    }

    public function testALongWaitIsALongLayover(): void
    {
        // 301 minutes: one past the threshold.
        $notes = $this->layoverNotes('10:00', '15:01');

        self::assertSame([['text' => 'Long layover', 'tone' => 'note']], $notes);
    }

    /**
     * The boundaries, which is where this would break without saying so.
     *
     * `< 90` is tight and `> 300` is long, so exactly 90 minutes and exactly
     * 300 are neither -- an hour and a half is a normal connection and five
     * hours is a normal wait. Turning either comparison inclusive would put a
     * warning on both.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unremarkableWaits(): iterable
    {
        yield 'exactly the tight threshold' => ['10:00', '11:30'];
        yield 'exactly the long threshold' => ['10:00', '15:00'];
        yield 'comfortably between them' => ['10:00', '12:30'];
    }

    #[DataProvider('unremarkableWaits')]
    public function testAWaitOnTheThresholdCarriesNoNote(string $arrive, string $depart): void
    {
        self::assertSame([], $this->layoverNotes($arrive, $depart));
    }

    /**
     * "Overnight" stacks with either duration note, and never with itself.
     *
     * A tight connection in the small hours is two problems -- the plane may be
     * missed, and it is being caught at 3am with the airport shut -- so both
     * are said. What is never said twice is the duration: it is tight or long,
     * not both, because the chip already prints the wait.
     */
    public function testAnOvernightWaitStacksWithTheDurationNote(): void
    {
        $notes = $this->layoverNotes('23:30', '00:30', overnight: true);

        self::assertSame(
            [
                ['text' => 'Tight connection', 'tone' => 'risk'],
                ['text' => 'Overnight', 'tone' => 'note'],
            ],
            $notes,
        );
    }

    public function testALayoverNeverCarriesMoreThanTwoNotes(): void
    {
        foreach ([['23:30', '00:30', true], ['23:00', '05:00', true], ['10:00', '11:00', false], ['10:00', '12:00', false]] as [$arrive, $depart, $overnight]) {
            $notes = $this->layoverNotes($arrive, $depart, $overnight);

            self::assertLessThanOrEqual(2, count($notes), $arrive . ' to ' . $depart);
            self::assertSame(
                array_unique(array_column($notes, 'text')),
                array_column($notes, 'text'),
                'a layover should never say the same thing twice',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Notices
    |--------------------------------------------------------------------------
    */

    /**
     * One warning per kind, however many stops earn it.
     *
     * A two-stop trip with two tight connections is still one thing to know
     * about. Without the keyed collection this would print the same red chip
     * twice, with different cities in it.
     */
    public function testNoticesAreDeduplicatedAcrossStops(): void
    {
        // All four airports are Canadian, so nothing here is a transit country
        // and the only thing to warn about is the two 60-minute connections.
        $notices = $this->direction($this->itinerary([
            $this->segment(from: 'YUL', to: 'YYZ', depart: '06:00', arrive: '07:00'),
            $this->segment(from: 'YYZ', to: 'YVR', depart: '08:00', arrive: '09:00'),
            $this->segment(from: 'YVR', to: 'YYC', depart: '10:00', arrive: '11:00'),
        ]))['notices'];

        self::assertSame(['Tight connection'], array_column($notices, 'label'));
        self::assertSame(['risk'], array_column($notices, 'severity'));
    }

    /**
     * A transit country that is neither end of the trip.
     *
     * Three-way: connecting at home or at the destination needs no transit
     * visa, and only the country in the middle does. Dropping either side of
     * the comparison would put a passport warning on a domestic connection.
     */
    public function testAThirdCountryRaisesATransitVisaCheck(): void
    {
        $notices = $this->direction($this->itinerary([
            $this->segment(from: 'YUL', country: 'Canada', to: 'CDG', arriveCountry: 'France', depart: '06:00', arrive: '18:00'),
            $this->segment(from: 'CDG', country: 'France', to: 'LHR', arriveCountry: 'United Kingdom', depart: '20:00', arrive: '21:00'),
        ]))['notices'];

        $visa = $this->notice($notices, 'Transit visa');

        self::assertSame('check', $visa['severity']);
        self::assertStringContainsString('France', $visa['text']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function connectionsAtHomeOrAway(): iterable
    {
        yield 'in the origin country' => ['Canada', 'United Kingdom'];
        yield 'in the destination country' => ['United Kingdom', 'United Kingdom'];
    }

    #[DataProvider('connectionsAtHomeOrAway')]
    public function testAConnectionInEitherEndsCountryNeedsNoVisa(string $stopCountry, string $endCountry): void
    {
        $notices = $this->direction($this->itinerary([
            $this->segment(from: 'YUL', country: 'Canada', to: 'STOP', arriveCountry: $stopCountry, depart: '06:00', arrive: '18:00'),
            $this->segment(from: 'STOP', country: $stopCountry, to: 'LHR', arriveCountry: $endCountry, depart: '20:00', arrive: '21:00'),
        ]))['notices'];

        self::assertNotContains('Transit visa', array_column($notices, 'label'));
    }

    public function testTwoAirlinesWarnAboutBags(): void
    {
        $notices = $this->direction($this->itinerary([
            $this->segment(carrier: 'AC', from: 'YUL', to: 'YYZ', depart: '06:00', arrive: '07:00'),
            $this->segment(carrier: 'BA', from: 'YYZ', to: 'LHR', depart: '09:00', arrive: '19:00'),
        ]))['notices'];

        self::assertSame('check', $this->notice($notices, 'Separate airlines')['severity']);
    }

    public function testOneAirlineDoesNot(): void
    {
        $notices = $this->direction($this->itinerary([
            $this->segment(carrier: 'AC', from: 'YUL', to: 'YYZ', depart: '06:00', arrive: '07:00'),
            $this->segment(carrier: 'AC', from: 'YYZ', to: 'LHR', depart: '09:00', arrive: '19:00'),
        ]))['notices'];

        self::assertNotContains('Separate airlines', array_column($notices, 'label'));
    }

    /**
     * A whole day door to door, and the boundary under it.
     *
     * Both itineraries below total exactly 1440 and 1441 minutes -- flying time
     * plus the wait, which is how StoredItinerary adds it up. Strictly greater,
     * so a day on the nose is not remarked on.
     */
    public function testAJourneyOverADayIsANote(): void
    {
        $notices = $this->direction($this->longTrip(extraMinutes: 1))['notices'];

        $long = $this->notice($notices, 'Long journey');

        self::assertSame('note', $long['severity']);
        self::assertStringContainsString('1d', $long['text']);
    }

    public function testAJourneyOfExactlyADayIsNot(): void
    {
        $notices = $this->direction($this->longTrip(extraMinutes: 0))['notices'];

        self::assertNotContains('Long journey', array_column($notices, 'label'));
    }

    /*
    |--------------------------------------------------------------------------
    | The route bar
    |--------------------------------------------------------------------------
    */

    public function testTheRouteBarAlternatesLegsAndStops(): void
    {
        $route = $this->direction($this->itinerary([
            $this->segment(from: 'YUL', to: 'YYZ', depart: '06:00', arrive: '07:00'),
            $this->segment(from: 'YYZ', to: 'LHR', depart: '09:00', arrive: '19:00'),
        ]))['route'];

        self::assertSame(['leg', 'stop', 'leg'], array_column($route, 'type'));
    }

    /**
     * Only the outer legs print a code, or the bar repeats every airport twice.
     */
    public function testOnlyTheFirstAndLastLegCarryTheirCodes(): void
    {
        $route = $this->direction($this->itinerary([
            $this->segment(from: 'YUL', to: 'YYZ', depart: '06:00', arrive: '07:00'),
            $this->segment(from: 'YYZ', to: 'LHR', depart: '09:00', arrive: '19:00'),
        ]))['route'];

        self::assertSame(['YUL', null, null], array_column($route, 'start_code'));
        self::assertSame([null, null, 'LHR'], array_column($route, 'end_code'));
        // The stop is the only part that names the airport under it.
        self::assertSame([null, 'YYZ', null], array_column($route, 'code'));
    }

    /**
     * Weights draw the bar, so a zero would make a part with no width at all.
     */
    public function testNoPartOfTheBarWeighsLessThanOne(): void
    {
        $route = $this->direction($this->itinerary([
            $this->segment(from: 'YUL', to: 'YYZ', depart: '06:00', arrive: '06:00', duration: 0),
            $this->segment(from: 'YYZ', to: 'LHR', depart: '06:00', arrive: '19:00'),
        ]))['route'];

        foreach ($route as $part) {
            self::assertGreaterThanOrEqual(1, $part['weight'], $part['type'] . ' should still be drawable');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{int, string}> */
    public static function stopCounts(): iterable
    {
        yield 'none' => [0, 'Direct'];
        yield 'one' => [1, '1 stop'];
        yield 'two' => [2, '2 stops'];
        yield 'three' => [3, '3 stops'];
    }

    #[DataProvider('stopCounts')]
    public function testStopsAreLabelledAndPluralised(int $stops, string $label): void
    {
        self::assertSame($label, new ItineraryPresenter()->stopsLabel($stops));
    }

    /**
     * @return iterable<string, array{int, string}>
     *
     * The zero is the one to know about: it prints nothing at all rather than
     * "0m". Every caller here is a real duration or a real wait, so it never
     * arrives -- but the template would print an empty span if one did.
     */
    public static function durations(): iterable
    {
        yield 'minutes only' => [45, '45m'];
        yield 'a whole hour, with no minutes shown' => [60, '1h'];
        yield 'hours and minutes' => [90, '1h 30m'];
        yield 'a whole day' => [1440, '1d'];
        yield 'a day and an hour' => [1500, '1d 1h'];
        yield 'nothing at all' => [0, ''];
    }

    #[DataProvider('durations')]
    public function testDurationsDropTheUnitsThatAreZero(int $minutes, string $expected): void
    {
        self::assertSame($expected, new ItineraryPresenter()->minutesToStringTime($minutes));
    }

    /**
     * The leg ids come back in flight order.
     *
     * This is what builds the checkout link and what a saved flight is stored
     * as, so the order is the itinerary rather than an implementation detail.
     */
    public function testTheLegIdsComeBackInFlightOrder(): void
    {
        $result = new ItineraryPresenter()->direction($this->itinerary([
            $this->segment(id: 41, from: 'YUL', to: 'YYZ', depart: '06:00', arrive: '07:00'),
            $this->segment(id: 42, from: 'YYZ', to: 'LHR', depart: '09:00', arrive: '19:00'),
        ]));

        self::assertSame([41, 42], $result['ids']);
    }

    /**
     * A badge slug the presenter has never heard of still renders.
     *
     * The repository decides these, so a new one can arrive here before it has
     * a label. It gets a neutral one rather than an exception on a results page.
     */
    public function testAnUnknownBadgeFallsBackToItsOwnSlug(): void
    {
        self::assertSame(
            ['label' => 'Something-new', 'tone' => 'secondary', 'icon' => 'star', 'text' => ''],
            new ItineraryPresenter()->badgeMeta('something-new'),
        );
    }

    /**
     * The logo comes off the CDN, not from anything in this repository.
     *
     * Asserted on the path and not the host: the host is `AWS_CLOUDFRONT` from
     * the environment, and a test that named it would be asserting somebody's
     * `.env`.
     */
    public function testACarrierLogoIsACdnPath(): void
    {
        self::assertStringEndsWith('/images/suppliers/AC.png', new ItineraryPresenter()->carrierLogo('AC'));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * The notes on the single layover of a two-segment trip.
     *
     * @return list<array{text: string, tone: string}>
     */
    private function layoverNotes(string $arrive, string $depart, bool $overnight = false): array
    {
        $layovers = $this->direction($this->itinerary([
            $this->segment(from: 'YUL', to: 'YYZ', depart: '05:00', arrive: $arrive),
            $this->segment(
                from: 'YYZ',
                to: 'LHR',
                depart: $depart,
                arrive: '23:00',
                // The second leg starts the next day where the wait crossed
                // midnight, or the subtraction runs backwards.
                day: $overnight ? self::NEXT_DAY : self::DAY,
            ),
        ]))['layovers'];

        self::assertCount(1, $layovers, 'the fixture has one layover to read notes off');

        return $layovers[0]['notes'];
    }

    /**
     * A two-leg trip totalling exactly a day, plus whatever is asked for.
     *
     * 690 minutes in the air each way and a 60-minute wait, which is 1440 --
     * and the stamps say the same, so the fixture is not lying about itself.
     */
    private function longTrip(int $extraMinutes): stdClass
    {
        return $this->itinerary([
            $this->segment(from: 'YUL', to: 'YYZ', depart: '06:00', arrive: '17:30', duration: 690),
            $this->segment(
                from: 'YYZ',
                to: 'LHR',
                depart: '18:30',
                arrive: '06:00',
                day: self::DAY,
                arriveDay: self::NEXT_DAY,
                duration: 690 + $extraMinutes,
            ),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $segments
     */
    private function itinerary(array $segments): stdClass
    {
        $itinerary = StoredItinerary::fromJson((string) json_encode($segments));

        self::assertNotNull($itinerary, 'the fixture should be renderable');

        return $itinerary;
    }

    /**
     * @return array<string, mixed>
     */
    private function direction(stdClass $itinerary): array
    {
        return new ItineraryPresenter()->direction($itinerary)['direction'];
    }

    /**
     * One leg, in the shape FlightFinder::mapLeg() writes and a booking stores.
     *
     * The `id` is not optional. `direction()` reads `$segment->id` unguarded,
     * on the same reasoning StoredItinerary gives for not checking carrier and
     * number: mapLeg() has written it first since the beginning, so a stored
     * segment without one is not a shape that exists. Leaving it off the
     * fixture raised a warning rather than failing, which is the wrong way to
     * find that out.
     *
     * @return array<string, mixed>
     */
    private function segment(
        int $id = 1,
        string $carrier = 'AC',
        string $from = 'YUL',
        string $to = 'LHR',
        string $depart = '06:00',
        string $arrive = '18:00',
        string $cabin = 'Y',
        int $duration = 420,
        ?string $country = null,
        ?string $arriveCountry = null,
        string $day = self::DAY,
        ?string $arriveDay = null,
    ): array {
        $country ??= $this->countryOf($from);
        $arriveCountry ??= $this->countryOf($to);

        return [
            'id' => $id,
            'carrier' => $carrier,
            'carrier_name' => $carrier . ' Airlines',
            'number' => $carrier . '-100',
            'duration' => $duration,
            'cabin_code' => $cabin,
            'depart' => [
                'airport_code' => $from,
                'airport_name' => $from . ' International',
                'airport_city' => $from . ' City',
                'airport_country' => $country,
                'date_time' => $day . ' ' . $depart . ':00',
            ],
            'arrive' => [
                'airport_code' => $to,
                'airport_name' => $to . ' International',
                'airport_city' => $to . ' City',
                'airport_country' => $arriveCountry,
                'date_time' => ($arriveDay ?? $day) . ' ' . $arrive . ':00',
            ],
        ];
    }

    /**
     * Where an airport is, or a demand that the fixture say so.
     *
     * Failing rather than defaulting: a country guessed for a new code is how
     * the incoherent first draft happened.
     */
    private function countryOf(string $code): string
    {
        self::assertArrayHasKey(
            $code,
            self::COUNTRY_OF,
            $code . ' is not in COUNTRY_OF -- add it, or pass the country explicitly',
        );

        return self::COUNTRY_OF[$code];
    }

    /**
     * @param list<array<string, string>> $notices
     * @return array<string, string>
     */
    private function notice(array $notices, string $label): array
    {
        foreach ($notices as $notice) {
            if ($notice['label'] === $label) {
                return $notice;
            }
        }

        self::fail($label . ' is not among: ' . implode(', ', array_column($notices, 'label')));
    }
}
