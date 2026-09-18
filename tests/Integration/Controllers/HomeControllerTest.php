<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Controllers;

use TripBuilder\CabinClass;
use TripBuilder\Controllers\HomeController;
use TripBuilder\SearchUrl;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * `HomeController::withExplorePrices()` -- C8 (#157)'s only real logic,
 * pulled out of `index()` and made `public` so it can be asserted on
 * directly rather than parsed back out of rendered HTML.
 *
 * Writes straight into `route_day_price`, the way
 * `RoutePriceRepositoryTest::testCheapestFindsTheLowestTotalAndItsDay` does
 * for the same reason: this is testing what reads the cache, not what
 * builds it.
 */
final class HomeControllerTest extends IntegrationTestCase
{
    private const string ORIGIN = 'ZZQ';
    private const string WARM_DESTINATION = 'ZZR';
    private const string COLD_DESTINATION = 'ZZS';

    /** @var list<array{country: string, city: string, code: string, title: string, image: string}> */
    private array $poi = [
        ['country' => 'Nowhere', 'city' => 'Warm', 'code' => self::WARM_DESTINATION, 'title' => 'Warm', 'image' => 'x.jpeg'],
        ['country' => 'Nowhere', 'city' => 'Cold', 'code' => self::COLD_DESTINATION, 'title' => 'Cold', 'image' => 'x.jpeg'],
    ];

    protected function tearDown(): void
    {
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ([self::WARM_DESTINATION, self::COLD_DESTINATION] as $to) {
            $this->connection()->execute(
                'DELETE FROM route_day_price WHERE from_code = ? AND to_code = ?',
                [self::ORIGIN, $to],
            );
        }
    }

    public function testWithNoOriginEveryCardIsPriceless(): void
    {
        $this->insertDayPrice(self::WARM_DESTINATION, self::day(10), 50.00, 10.00);

        $cards = HomeController::withExplorePrices($this->poi, null, $this->connection());

        foreach ($cards as $card) {
            self::assertNull($card['price']);
            self::assertNull($card['url']);
        }
    }

    public function testAWarmRouteGetsAPriceAndALink(): void
    {
        $this->insertDayPrice(self::WARM_DESTINATION, self::day(10), 50.00, 10.00);

        $cards = HomeController::withExplorePrices($this->poi, self::ORIGIN, $this->connection());
        $warm = self::cardFor($cards, self::WARM_DESTINATION);

        self::assertSame(60.00, $warm['price']);
        self::assertSame(
            new SearchUrl(self::ORIGIN, self::WARM_DESTINATION, self::day(10), null)->path(),
            $warm['url'],
        );
    }

    public function testAColdRouteIsLeftAlone(): void
    {
        // Nothing inserted for COLD_DESTINATION -- flights:explore has not
        // warmed it, and this must not build it live.
        $cards = HomeController::withExplorePrices($this->poi, self::ORIGIN, $this->connection());
        $cold = self::cardFor($cards, self::COLD_DESTINATION);

        self::assertNull($cold['price']);
        self::assertNull($cold['url']);
    }

    public function testAPoiThatIsTheOriginIsLeftAlone(): void
    {
        $this->insertDayPrice(self::WARM_DESTINATION, self::day(10), 50.00, 10.00);

        // Asking the warm destination's own price from itself would be
        // asking route_day_price for a route that cannot exist.
        $cards = HomeController::withExplorePrices($this->poi, self::WARM_DESTINATION, $this->connection());
        $warm = self::cardFor($cards, self::WARM_DESTINATION);

        self::assertNull($warm['price']);
        self::assertNull($warm['url']);
    }

    public function testACheaperDayOutsideThisMonthDoesNotWin(): void
    {
        $this->insertDayPrice(self::WARM_DESTINATION, self::day(10), 50.00, 10.00);
        $this->insertDayPrice(self::WARM_DESTINATION, self::day(60), 5.00, 1.00);

        $cards = HomeController::withExplorePrices($this->poi, self::ORIGIN, $this->connection());
        $warm = self::cardFor($cards, self::WARM_DESTINATION);

        self::assertSame(60.00, $warm['price'], 'the cheaper day is outside the 30-day window "this month" means');
    }

    /**
     * @param list<array{country: string, city: string, code: string, title: string, image: string, price: float|null, url: string|null}> $cards
     * @return array{country: string, city: string, code: string, title: string, image: string, price: float|null, url: string|null}
     */
    private static function cardFor(array $cards, string $code): array
    {
        foreach ($cards as $card) {
            if ($card['code'] === $code) {
                return $card;
            }
        }

        self::fail($code . ' was not in the cards returned');
    }

    private function insertDayPrice(string $to, string $date, float $base, float $tax): void
    {
        $this->connection()->execute(
            'INSERT INTO route_day_price (from_code, to_code, cabin, depart_date, price_base, price_tax)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [self::ORIGIN, $to, CabinClass::Economy->value, $date, $base, $tax],
        );
    }

    private static function day(int $days): string
    {
        return date('Y-m-d', (int) strtotime('+' . $days . ' day'));
    }
}
