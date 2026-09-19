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
 * `HomeController::travelDeals()` -- C10 (#395), the "Travel deals under
 * $X" carousel. Reuses `FlightRepository::cheapestPerDestinationCity()`
 * as-is (built for a country page, structurally the same question), so
 * what this tests is the composition around it: the origin resolving to
 * real airports, the ceiling being computed from what is actually shown
 * rather than fixed, and the link matching the fare it is attached to.
 *
 * Fixtures use real major airports (`YUL`, `LHR`, `CDG`) rather than
 * sentinel codes, because `AirportRepository::enabled(true)` -- the
 * destination side -- reads `is_major` off the real seed and cannot be
 * faked without one.
 */
final class HomeControllerTest extends IntegrationTestCase
{
    private const string ORIGIN = 'YUL';
    private const string CHEAPER_DESTINATION = 'LHR';
    private const string PRICIER_DESTINATION = 'CDG';

    /** Cheap enough that nothing generated can undercut it -- see FarePricing::FIXED_DOLLARS. */
    private const float CHEAPER_BASE = 5.00;
    private const float CHEAPER_TAX = 1.00;
    private const float PRICIER_BASE = 15.00;
    private const float PRICIER_TAX = 1.00;

    /** @var list<int> */
    private array $flightIds = [];

    protected function tearDown(): void
    {
        foreach ($this->flightIds as $id) {
            $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$id]);
        }

        $this->connection()->execute('DELETE FROM settings WHERE setting_key = ?', ['site.home.deals_limit']);
        $this->connection()->execute('DELETE FROM setting_changes WHERE setting_key = ?', ['site.home.deals_limit']);
        Settings::forget();
    }

    /**
     * No origin at all -- no recent search, no Cloudflare geo header --
     * falls back to Montreal's own airport rather than showing nothing.
     * See `HomeController::FALLBACK_ORIGIN`'s own docblock for why `YUL`
     * and not the city code `YMQ`.
     *
     * Checked against explicitly passing `YUL` rather than a hardcoded
     * expectation, so this proves the fallback is that airport
     * specifically without this test needing to predict its real fares.
     */
    public function testNoOriginFallsBackToMontreal(): void
    {
        $airports = new AirportRepository($this->connection());

        $withNull = HomeController::travelDeals(null, $airports, $this->connection());
        $withMontreal = HomeController::travelDeals('YUL', $airports, $this->connection());

        self::assertSame($withMontreal, $withNull);
    }

    public function testAnUnresolvableOriginShowsNothing(): void
    {
        $deals = HomeController::travelDeals('ZZZ', new AirportRepository($this->connection()), $this->connection());

        self::assertNull($deals['ceiling']);
        self::assertSame([], $deals['cards']);
    }

    /**
     * G22 (#426): the card count follows `PanelSetting::HomeDealsLimit`, not
     * a fixed number. The real network already reaches plenty of
     * destinations from Montreal, so this needs no fixture -- only that an
     * override of 1 actually caps the cards at 1 rather than the config
     * default of 10.
     */
    public function testTheCardCountFollowsTheAdminOverride(): void
    {
        new SettingsRepository($this->connection())->set('site.home.deals_limit', 1);
        Settings::forget();

        $deals = HomeController::travelDeals(self::ORIGIN, new AirportRepository($this->connection()), $this->connection());

        self::assertCount(1, $deals['cards']);
    }

    /**
     * Checked as a self-consistency invariant rather than a hardcoded
     * number: with the deals limit at its config default of 10 and
     * hundreds of real destination cities reachable from a real origin,
     * eight of the ten shown are
     * whatever the generator happens to have made cheapest that run --
     * only that the ceiling always equals the priciest *shown* card,
     * never a fixed figure, is this repository's to prove.
     */
    public function testCeilingIsTheRoundedUpTopOfWhatIsActuallyShown(): void
    {
        $this->flightIds[] = $this->insertFixtureFlight(self::CHEAPER_DESTINATION, self::CHEAPER_BASE, self::CHEAPER_TAX);
        $this->flightIds[] = $this->insertFixtureFlight(self::PRICIER_DESTINATION, self::PRICIER_BASE, self::PRICIER_TAX);

        $deals = HomeController::travelDeals(self::ORIGIN, new AirportRepository($this->connection()), $this->connection());

        self::assertNotEmpty($deals['cards']);

        $highest = max(array_column($deals['cards'], 'price'));

        self::assertSame(
            (int) (ceil($highest / 10) * 10),
            $deals['ceiling'],
            'the ceiling should track whatever the priciest shown card actually is',
        );
    }

    public function testCardsAreSortedCheapestFirstAndLinkToTheExactFareShown(): void
    {
        $this->flightIds[] = $this->insertFixtureFlight(self::PRICIER_DESTINATION, self::PRICIER_BASE, self::PRICIER_TAX);
        $this->flightIds[] = $this->insertFixtureFlight(self::CHEAPER_DESTINATION, self::CHEAPER_BASE, self::CHEAPER_TAX);

        $deals = HomeController::travelDeals(self::ORIGIN, new AirportRepository($this->connection()), $this->connection());

        $cities = array_column($deals['cards'], 'city');
        $cheaperIndex = array_search('London', $cities, true);
        $pricierIndex = array_search('Paris', $cities, true);

        self::assertNotFalse($cheaperIndex, 'London fixture missing from the cards');
        self::assertNotFalse($pricierIndex, 'Paris fixture missing from the cards');
        self::assertLessThan($pricierIndex, $cheaperIndex, 'cheaper destination should sort before the pricier one');

        $cheaperCard = $deals['cards'][$cheaperIndex];
        self::assertSame(self::CHEAPER_BASE + self::CHEAPER_TAX, $cheaperCard['price']);
        // The URL is built from from_city_code/to_city_code -- YUL's own city
        // is YMQ and LHR's is LON, neither the airport code itself.
        self::assertSame(
            new SearchUrl('YMQ', 'LON', date('Y-m-d', strtotime('+5 day')), null)->path(),
            $cheaperCard['url'],
        );
    }

    private function insertFixtureFlight(string $to, float $base, float $tax): int
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
                'AC', 999, '789', self::ORIGIN, $departureTime, $departureUtc,
                $to, date('Y-m-d H:i:s', (int) strtotime($departureTime . ' +2 hour')),
                1000, 120, CabinClass::Economy->bit(), $base, $tax, 4.10,
            ],
        );
    }
}
