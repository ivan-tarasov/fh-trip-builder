<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Controllers;

use TripBuilder\CabinClass;
use TripBuilder\Controllers\HomeController;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\SettingsRepository;
use TripBuilder\SearchUrl;
use TripBuilder\Settings;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * `HomeController::popularFlights()` -- C12 (#401), the "Popular flights
 * near you" domestic/international tabs. Reuses
 * `FlightRepository::cheapestPerDestinationCity()` and
 * `AirportRepository::enabledByCountry()` as-is, so what this tests is the
 * split itself: a fixture on each side of the origin's own country lands
 * in the tab that side belongs to, and nowhere else.
 *
 * Real major airports (`YUL`, `YYZ`/`YTO`, `LHR`), the same reason
 * `HomeControllerTest`'s own docblock gives -- `enabledByCountry()` reads
 * `is_major`/`country_code` off the real seed.
 */
final class HomeControllerPopularFlightsTest extends IntegrationTestCase
{
    private const string ORIGIN = 'YUL';

    /** Toronto's airport code, to insert against -- its city code is YTO, which is what the query groups by. */
    private const string DOMESTIC_DESTINATION = 'YYZ';
    private const string DOMESTIC_DESTINATION_CITY = 'YTO';
    private const string INTERNATIONAL_DESTINATION = 'LHR';

    /** Cheap enough that nothing generated can undercut it -- see FarePricing::FIXED_DOLLARS. */
    private const float FIXTURE_BASE = 5.00;
    private const float FIXTURE_TAX = 1.00;

    /** @var list<int> */
    private array $flightIds = [];

    protected function tearDown(): void
    {
        foreach ($this->flightIds as $id) {
            $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$id]);
        }

        $this->connection()->execute('DELETE FROM settings WHERE setting_key = ?', ['site.home.popular_limit']);
        $this->connection()->execute('DELETE FROM setting_changes WHERE setting_key = ?', ['site.home.popular_limit']);
        Settings::forget();
    }

    public function testAnUnresolvableOriginShowsNothing(): void
    {
        $tabs = HomeController::popularFlights('ZZZ', new AirportRepository($this->connection()), $this->connection());

        self::assertSame([], $tabs);
    }

    public function testDomesticAndInternationalFixturesLandInTheirOwnTab(): void
    {
        $this->flightIds[] = $this->insertFixtureFlight(self::DOMESTIC_DESTINATION);
        $this->flightIds[] = $this->insertFixtureFlight(self::INTERNATIONAL_DESTINATION);

        $tabs = HomeController::popularFlights(self::ORIGIN, new AirportRepository($this->connection()), $this->connection());
        $byId = [];

        foreach ($tabs as $tab) {
            $byId[$tab['id']] = $tab;
        }

        self::assertArrayHasKey('home', $byId, 'a domestic fixture exists, so the domestic tab should not be dropped');
        self::assertArrayHasKey('away', $byId, 'an international fixture exists, so the international tab should not be dropped');

        $homeCities = array_column($byId['home']['fares'], 'to_city_code');
        $awayCities = array_column($byId['away']['fares'], 'to_city_code');

        self::assertContains(self::DOMESTIC_DESTINATION_CITY, $homeCities, 'the domestic fixture should show up in the domestic tab');
        self::assertContains('LON', $awayCities, 'the international fixture should show up in the international tab');
    }

    /**
     * G22 (#426): fares per tab follows `PanelSetting::HomePopularLimit`,
     * not a constant. The real Canadian network already reaches plenty of
     * cities from Montreal, so this needs no fixture -- only that an
     * override of 1 actually caps a tab at 1 rather than the config
     * default of 8.
     */
    public function testFaresPerTabFollowTheAdminOverride(): void
    {
        new SettingsRepository($this->connection())->set('site.home.popular_limit', 1);
        Settings::forget();

        $tabs = HomeController::popularFlights(self::ORIGIN, new AirportRepository($this->connection()), $this->connection());

        self::assertNotEmpty($tabs);

        foreach ($tabs as $tab) {
            self::assertCount(1, $tab['fares'], "{$tab['id']} tab should be capped at the overridden limit");
        }
    }

    public function testEveryCityInTheDomesticTabSharesTheOriginsCountryAndEveryCityAbroadDoesNot(): void
    {
        $this->flightIds[] = $this->insertFixtureFlight(self::DOMESTIC_DESTINATION);
        $this->flightIds[] = $this->insertFixtureFlight(self::INTERNATIONAL_DESTINATION);

        $tabs = HomeController::popularFlights(self::ORIGIN, new AirportRepository($this->connection()), $this->connection());
        $countryByCityCode = fn(string $cityCode): ?string => $this->connection()->fetchValue(
            'SELECT country_code FROM airports WHERE city_code = ? LIMIT 1',
            [$cityCode],
        );

        foreach ($tabs as $tab) {
            foreach ($tab['fares'] as $fare) {
                $country = $countryByCityCode($fare['to_city_code']);

                if ($tab['id'] === 'home') {
                    self::assertSame('CA', $country, "{$fare['to_city_code']} in the domestic tab should share the origin's country");
                } else {
                    self::assertNotSame('CA', $country, "{$fare['to_city_code']} in the international tab should not share the origin's country");
                }
            }
        }
    }

    public function testFareLinksMatchTheExactRouteShown(): void
    {
        $this->flightIds[] = $this->insertFixtureFlight(self::DOMESTIC_DESTINATION);

        $tabs = HomeController::popularFlights(self::ORIGIN, new AirportRepository($this->connection()), $this->connection());
        $home = array_values(array_filter($tabs, static fn(array $tab): bool => $tab['id'] === 'home'))[0];
        $fixture = array_values(array_filter(
            $home['fares'],
            static fn(array $fare): bool => $fare['to_city_code'] === self::DOMESTIC_DESTINATION_CITY,
        ))[0];

        self::assertSame(
            new SearchUrl(
                $fixture['from_city_code'],
                self::DOMESTIC_DESTINATION_CITY,
                substr($fixture['departure_time'], 0, 10),
                null,
            )->path(),
            $fixture['search'],
        );
    }

    private function insertFixtureFlight(string $to): int
    {
        /** @var float $timezone */
        $timezone = $this->connection()->fetchValue('SELECT timezone FROM airports WHERE code = ?', [self::ORIGIN]);

        $departureTime = date('Y-m-d H:i:s', (int) strtotime('+5 day'));
        $departureUtc = date('Y-m-d H:i:s', (int) strtotime($departureTime) - (int) round($timezone * 3600));

        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, aircraft, departure_airport, departure_time, departure_utc,'
            . ' arrival_airport, arrival_time, distance, duration, cabins, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'AC', 998, '789', self::ORIGIN, $departureTime, $departureUtc,
                $to, date('Y-m-d H:i:s', (int) strtotime($departureTime . ' +2 hour')),
                1000, 120, CabinClass::Economy->bit(), self::FIXTURE_BASE, self::FIXTURE_TAX, 4.10,
            ],
        );
    }
}
