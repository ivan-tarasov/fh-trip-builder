<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Api\Flights;

use PHPUnit\Framework\TestCase;
use TripBuilder\Api\Flights\FlightFilters;
use TripBuilder\Config;

final class FlightFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        // The predicates read bucket and country lists from config. Name the
        // environment rather than leaning on APP_ENV, which the test run does
        // not set — without it the lists come back empty and every predicate
        // silently passes.
        new Config('common');
    }

    /**
     * A candidate row shaped like the ones candidateSql produces: a two-leg
     * AF -> KL itinerary connecting at Amsterdam.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return $overrides + [
            'stops' => 1,
            'price_base' => 900.0,
            'price_tax' => 100.0,
            'duration' => 600,
            'depart_time' => '2026-09-15 08:30:00',
            'arrive_time' => '2026-09-15 18:45:00',
            'carriers' => 'AF,KL',
            'aircraft' => '320,789',
            'dep_airport' => 'CDG',
            'arr_airport' => 'JFK',
            'stops_at' => 'AMS',
            'layover_minutes' => 120,
            'stop1_in' => '2026-09-15 10:00:00',
            'stop1_out' => '2026-09-15 12:00:00',
            'stop2_in' => null,
            'stop2_out' => null,
            'stop_countries' => ['NL'],
            'origin_country' => 'FR',
            'destination_country' => 'US',
        ];
    }

    public function testNoFiltersMatchEverything(): void
    {
        $filters = new FlightFilters();

        self::assertTrue($filters->isEmpty());
        self::assertTrue($filters->matches($this->candidate()));
    }

    public function testStopsFilter(): void
    {
        self::assertTrue(new FlightFilters(stops: [1])->matches($this->candidate()));
        self::assertFalse(new FlightFilters(stops: [0])->matches($this->candidate()));
        self::assertTrue(new FlightFilters(stops: [0, 1, 2])->matches($this->candidate()));
    }

    public function testAirlinesFilterNeedsEveryLegNotJustOne(): void
    {
        // Selecting Air France should not return a ticket that finishes on KLM:
        // you picked an airline to fly, not one to fly part of the way.
        self::assertFalse(new FlightFilters(airlines: ['AF'])->matches($this->candidate()));
        self::assertTrue(new FlightFilters(airlines: ['AF', 'KL'])->matches($this->candidate()));
        self::assertTrue(new FlightFilters(airlines: ['AF'])->matches($this->candidate(['carriers' => 'AF'])));
    }

    public function testSingleCarrierFilter(): void
    {
        $filters = new FlightFilters(singleCarrier: true);

        self::assertFalse($filters->matches($this->candidate()));
        self::assertTrue($filters->matches($this->candidate(['carriers' => 'AF,AF'])));
        self::assertTrue($filters->matches($this->candidate(['carriers' => 'AF'])));
    }

    public function testPriceAndDurationAreInclusiveBounds(): void
    {
        // Total is 1000 exactly; a "up to $1000" slider must include it.
        self::assertTrue(new FlightFilters(maxPrice: 1000.0)->matches($this->candidate()));
        self::assertFalse(new FlightFilters(maxPrice: 999.99)->matches($this->candidate()));
        self::assertTrue(new FlightFilters(maxDuration: 600)->matches($this->candidate()));
        self::assertFalse(new FlightFilters(maxDuration: 599)->matches($this->candidate()));
    }

    public function testTimeBucketsAreHalfOpenSoAStampBelongsToOne(): void
    {
        $at = static fn(string $time): array => ['depart_time' => '2026-09-15 ' . $time];

        // 06:00 opens "morning" and closes "early morning".
        self::assertTrue(new FlightFilters(departBuckets: ['morning'])->matches($this->candidate($at('06:00:00'))));
        self::assertFalse(new FlightFilters(departBuckets: ['early_morning'])->matches($this->candidate($at('06:00:00'))));
        self::assertTrue(new FlightFilters(departBuckets: ['early_morning'])->matches($this->candidate($at('05:59:00'))));

        // Midnight and the last minute of the day both land somewhere.
        self::assertTrue(new FlightFilters(departBuckets: ['early_morning'])->matches($this->candidate($at('00:00:00'))));
        self::assertTrue(new FlightFilters(departBuckets: ['late_evening'])->matches($this->candidate($at('23:59:00'))));
    }

    public function testCustomWindowOverridesBuckets(): void
    {
        // The slider is the finer control, so when it is set it decides.
        $filters = new FlightFilters(departWindow: [0, 60], departBuckets: ['morning']);

        self::assertFalse($filters->matches($this->candidate()));
        self::assertTrue($filters->matches($this->candidate(['depart_time' => '2026-09-15 00:30:00'])));
    }

    public function testLayoverAirportsAllowDirectFlightsThrough(): void
    {
        // The control is "connections I will accept". A direct flight has none
        // to object to, so restricting connections must not hide it.
        $direct = $this->candidate(['stops' => 0, 'stops_at' => '', 'carriers' => 'AF']);

        self::assertTrue(new FlightFilters(layoverAirports: ['LHR'])->matches($direct));
        self::assertFalse(new FlightFilters(layoverAirports: ['LHR'])->matches($this->candidate()));
        self::assertTrue(new FlightFilters(layoverAirports: ['AMS'])->matches($this->candidate()));
    }

    public function testEveryLayoverMustBeAllowedNotJustOne(): void
    {
        $twoStop = $this->candidate(['stops' => 2, 'stops_at' => 'AMS,IAD']);

        self::assertFalse(new FlightFilters(layoverAirports: ['AMS'])->matches($twoStop));
        self::assertTrue(new FlightFilters(layoverAirports: ['AMS', 'IAD'])->matches($twoStop));
    }

    public function testAircraftMatchesWhenAnyLegUsesTheType(): void
    {
        // Unlike airlines: you are picking a plane you want to be on.
        self::assertTrue(new FlightFilters(aircraft: ['789'])->matches($this->candidate()));
        self::assertTrue(new FlightFilters(aircraft: ['320'])->matches($this->candidate()));
        self::assertFalse(new FlightFilters(aircraft: ['77W'])->matches($this->candidate()));
    }

    public function testNightLayoverDetection(): void
    {
        $filters = new FlightFilters(noNightLayover: true);

        // A midday wait is fine.
        self::assertTrue($filters->matches($this->candidate()));

        // One running 22:00 -> 07:00 is not.
        self::assertFalse($filters->matches($this->candidate([
            'stop1_in' => '2026-09-15 22:00:00',
            'stop1_out' => '2026-09-16 07:00:00',
        ])));

        // Nor is one that only clips the start of the night.
        self::assertFalse($filters->matches($this->candidate([
            'stop1_in' => '2026-09-15 22:30:00',
            'stop1_out' => '2026-09-15 23:30:00',
        ])));

        // A direct flight has no layover to be nocturnal.
        self::assertTrue($filters->matches($this->candidate([
            'stops' => 0, 'stop1_in' => null, 'stop1_out' => null,
        ])));
    }

    public function testNightLayoverChecksEveryConnectionNotOnlyTheFirst(): void
    {
        $filters = new FlightFilters(noNightLayover: true);

        self::assertFalse($filters->matches($this->candidate([
            'stops' => 2,
            'stops_at' => 'AMS,IAD',
            'stop2_in' => '2026-09-15 23:10:00',
            'stop2_out' => '2026-09-16 06:30:00',
        ])));
    }

    public function testGulfAndTransitVisaUseLayoverCountries(): void
    {
        $viaDubai = $this->candidate(['stops_at' => 'DXB', 'stop_countries' => ['AE']]);

        self::assertFalse(new FlightFilters(noGulfLayover: true)->matches($viaDubai));
        self::assertTrue(new FlightFilters(noGulfLayover: true)->matches($this->candidate()));

        // Connecting in a third country is what needs a transit visa; stopping
        // inside the origin's or destination's own country does not.
        self::assertFalse(new FlightFilters(noVisaLayover: true)->matches($this->candidate()));
        self::assertTrue(new FlightFilters(noVisaLayover: true)->matches(
            $this->candidate(['stop_countries' => ['FR']]),
        ));
        self::assertTrue(new FlightFilters(noVisaLayover: true)->matches(
            $this->candidate(['stop_countries' => ['US']]),
        ));
    }

    public function testMatchesCanLiftOneDimension(): void
    {
        // How availability is measured: the dimension being reported on must not
        // filter out the very itineraries that would populate its options.
        $filters = new FlightFilters(stops: [0], airlines: ['AF', 'KL']);
        $candidate = $this->candidate();

        self::assertFalse($filters->matches($candidate));
        self::assertTrue($filters->matches($candidate, FlightFilters::DIM_STOPS));

        // Lifting an unrelated dimension does not rescue it.
        self::assertFalse($filters->matches($candidate, FlightFilters::DIM_AIRLINES));
    }

    public function testNeedsLayoverCountriesOnlyForCountryFilters(): void
    {
        self::assertFalse(new FlightFilters()->needsLayoverCountries());
        self::assertFalse(new FlightFilters(stops: [0])->needsLayoverCountries());
        self::assertTrue(new FlightFilters(noGulfLayover: true)->needsLayoverCountries());
        self::assertTrue(new FlightFilters(noVisaLayover: true)->needsLayoverCountries());
    }
}
