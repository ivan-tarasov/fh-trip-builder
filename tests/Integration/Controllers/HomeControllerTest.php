<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Controllers;

use TripBuilder\CabinClass;
use TripBuilder\Controllers\HomeController;
use TripBuilder\Helper;
use TripBuilder\Noah\Flights\FarePricing;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * `HomeController::withCheapestFares()` -- C8 (#157)'s redesigned POI
 * pricing, no longer personalised to a visitor's own origin (that version
 * went stale the moment somebody edited the "From" field without
 * submitting, and needed a scheduled job to stay warm). This reads real
 * traffic-weighted origins the same way `CityController::fares()` already
 * does for the destination's own page, and always links there.
 *
 * The origin used is read from the real seeded network
 * (`CityRepository::busiestOriginAirports()`) rather than assumed, the same
 * reason `RouteRepositoryTest` reads `searched()` back rather than
 * hardcoding a city -- which real airport counts as "busiest" is a fact
 * about the seed, not something a test should guess at.
 */
final class HomeControllerTest extends IntegrationTestCase
{
    private const string DESTINATION_CITY = 'YMQ';

    /** Cheap enough that nothing generated can undercut it -- see FarePricing::FIXED_DOLLARS. */
    private const float FIXTURE_BASE = 5.00;
    private const float FIXTURE_TAX = 1.00;

    private ?int $flightId = null;

    protected function tearDown(): void
    {
        if ($this->flightId !== null) {
            $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$this->flightId]);
        }
    }

    public function testAnUnknownCodeIsLeftAlone(): void
    {
        $card = ['country' => 'Nowhere', 'city' => 'Nowhere', 'code' => 'ZZZ', 'title' => 'Nowhere', 'image' => 'x.jpeg'];

        $cards = HomeController::withCheapestFares([$card], $this->connection());

        self::assertNull($cards[0]['price']);
        self::assertNull($cards[0]['url']);
    }

    public function testARealDestinationAlwaysLinksToItsOwnCityPage(): void
    {
        $cities = new CityRepository($this->connection());
        $city = $cities->byCode(self::DESTINATION_CITY);

        self::assertNotNull($city, 'fixture assumes ' . self::DESTINATION_CITY . ' is a real seeded city');

        $card = ['country' => 'Canada', 'city' => 'Montreal', 'code' => self::DESTINATION_CITY, 'title' => 'x', 'image' => 'x.jpeg'];

        $cards = HomeController::withCheapestFares([$card], $this->connection());

        self::assertSame(
            '/city/' . Helper::placeSlug((string) $city['name'], (string) $city['code']),
            $cards[0]['url'],
            'a real destination must always link to its own page, whether or not a fare was found this instant',
        );
    }

    public function testACheapFixtureFromARealBusyOriginWinsThePrice(): void
    {
        $cities = new CityRepository($this->connection());
        $city = $cities->byCode(self::DESTINATION_CITY);

        self::assertNotNull($city);

        // The floor is measured, not guessed -- the same assumption
        // RoutePriceRepositoryTest makes for the same reason.
        $floor = FarePricing::FIXED_DOLLARS * min(FarePricing::VARIANCE_PERCENT) / 100;
        self::assertGreaterThan(self::FIXTURE_BASE + self::FIXTURE_TAX, $floor, 'a generated fare can now undercut the fixture');

        $origins = [
            ...$cities->busiestOriginAirports(self::DESTINATION_CITY, (string) $city['country_code'], true, 8),
            ...$cities->busiestOriginAirports(self::DESTINATION_CITY, (string) $city['country_code'], false, 8),
        ];

        if ($origins === []) {
            self::markTestSkipped('no real busy origin exists for ' . self::DESTINATION_CITY . ' in this seed');
        }

        $destinations = array_column($cities->airports(self::DESTINATION_CITY), 'code');
        self::assertNotEmpty($destinations);

        $this->flightId = $this->insertFixtureFlight($origins[0], $destinations[0]);

        $card = ['country' => 'Canada', 'city' => 'Montreal', 'code' => self::DESTINATION_CITY, 'title' => 'x', 'image' => 'x.jpeg'];

        $cards = HomeController::withCheapestFares([$card], $this->connection());

        self::assertNotNull($cards[0]['price']);
        self::assertLessThanOrEqual(
            self::FIXTURE_BASE + self::FIXTURE_TAX,
            $cards[0]['price'],
            'the fixture is the cheapest thing on this route, so nothing should have beaten it',
        );
    }

    /** A direct flight, departing far enough out that no real airport's UTC offset could put it in the past. */
    private function insertFixtureFlight(string $from, string $to): int
    {
        /** @var float $timezone */
        $timezone = $this->connection()->fetchValue('SELECT timezone FROM airports WHERE code = ?', [$from]);

        $departureTime = date('Y-m-d H:i:s', (int) strtotime('+5 day'));
        $departureUtc = date(
            'Y-m-d H:i:s',
            (int) strtotime($departureTime) - (int) round($timezone * 3600),
        );

        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, aircraft, departure_airport, departure_time, departure_utc,'
            . ' arrival_airport, arrival_time, distance, duration, cabins, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'AC', 999, '789', $from, $departureTime, $departureUtc,
                $to, date('Y-m-d H:i:s', (int) strtotime($departureTime . ' +2 hour')),
                1000, 120, CabinClass::Economy->bit(), self::FIXTURE_BASE, self::FIXTURE_TAX, 4.10,
            ],
        );
    }
}
