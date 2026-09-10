<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use TripBuilder\CabinClass;
use TripBuilder\Config;
use TripBuilder\Controllers\MyController;
use TripBuilder\Controllers\SearchController;
use TripBuilder\Http\Input;
use TripBuilder\Http\Request;
use TripBuilder\Routes;
use TripBuilder\SearchUrl;
use TripBuilder\Service\FlightFinder;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\Timer;
use TripBuilder\View\ItineraryPresenter;

/**
 * What a saved flight comes back as.
 *
 * The saved list is a cookie of leg ids and nothing else, deliberately: prices
 * and times are read fresh, so a card can never show a stale fare. But the
 * page asked for them with no cabin, and `FlightFinder::itinerary()` defaults
 * to economy — so a business-class flight saved from a search came back
 * labelled *and priced* as economy. The price is the serious half: the card
 * quoted a fare nobody had been offered.
 *
 * The cabin now rides in the key, `12-34:C`, and economy is left off so every
 * key written before this still means what it always meant.
 *
 * Driven through the controller rather than the finder, because the finder was
 * never wrong — it takes a cabin and always has. What was missing was anything
 * passing one, and that is only visible from the page.
 */
final class SavedFlightCabinTest extends IntegrationTestCase
{
    // Past anything flights:add generates, so the fixture is the whole route.
    private const DATE = '2027-04-12';
    private const FROM = 'ABV';
    private const VIA = 'ACC';
    private const TO = 'ABJ';

    /** @var list<int> */
    private array $inserted = [];

    protected function setUp(): void
    {
        new Config('common');
        Timer::start();

        $_COOKIE = [];

        // One connection, so the card has a layover to draw and the itinerary
        // is not a single leg.
        $this->inserted = [
            $this->insertFlight(self::FROM, '07:00:00', self::VIA, '09:00:00'),
            $this->insertFlight(self::VIA, '11:00:00', self::TO, '13:00:00'),
        ];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];

        // A skip raised from a teardown is a failure, not a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null || $this->inserted === []) {
            return;
        }

        $this->connection()->execute(
            'DELETE FROM flights WHERE id IN (' . implode(', ', array_fill(0, count($this->inserted), '?')) . ')',
            $this->inserted,
        );
    }

    public function testAKeyCarryingABusinessCabinRendersBusiness(): void
    {
        $page = $this->savedPage($this->key() . ':C');

        self::assertStringContainsString('· Business', $page, 'the legs should read as business');
        self::assertStringNotContainsString('· Economy', $page);
    }

    /**
     * And a key from before the cabin was carried still means economy.
     *
     * Not a compatibility nicety: everything saved before today *was* priced
     * as economy, because economy was the only thing this page asked for. The
     * bare key is the honest spelling of what those saves are.
     */
    public function testABareKeyStillMeansEconomy(): void
    {
        $page = $this->savedPage($this->key());

        self::assertStringContainsString('· Economy', $page);
        self::assertStringNotContainsString('· Business', $page);
    }

    /**
     * The price follows the cabin, which is the half that was costing money.
     *
     * Both figures come from the app's own pricing and formatting rather than
     * being written down here, so this asserts that the page shows the fare
     * for the cabin it names — not that a particular number is right.
     */
    public function testTheCardIsPricedInTheCabinItNames(): void
    {
        $economy = $this->priceShown(CabinClass::Economy);
        $business = $this->priceShown(CabinClass::Business);

        self::assertNotSame(
            $economy,
            $business,
            'precondition: business must cost more than economy on this route, or this proves nothing',
        );

        self::assertStringContainsString($business, $this->savedPage($this->key() . ':C'));
        self::assertStringContainsString($economy, $this->savedPage($this->key()));
    }

    /**
     * The "search again" link lands in the cabin that was saved.
     *
     * SearchUrl spells the cabin into every path it writes, economy included,
     * so the letter is always there to check.
     */
    public function testTheSearchAgainLinkKeepsTheCabin(): void
    {
        self::assertMatchesRegularExpression(
            '#/search/[A-Z0-9]+C\d#',
            $this->savedPage($this->key() . ':C'),
            'the link should ask for business again',
        );

        self::assertMatchesRegularExpression(
            '#/search/[A-Z0-9]+Y\d#',
            $this->savedPage($this->key()),
        );
    }

    /**
     * @return iterable<string, array{string}>
     *
     * The cookie is written by the browser, so a key is untrusted input. A
     * cabin letter outside the four this app sells is a hand edit, and a hand
     * edit is dropped rather than repaired.
     */
    public static function malformedKeys(): iterable
    {
        yield 'a cabin this app does not sell' => [':Q'];
        yield 'a cabin in lower case' => [':c'];
        yield 'two cabins' => [':C:Y'];
        yield 'a trailing colon' => [':'];
    }

    #[DataProvider('malformedKeys')]
    public function testAMalformedCabinDropsTheCardEntirely(string $suffix): void
    {
        $page = $this->savedPage($this->key() . $suffix);

        self::assertStringNotContainsString('saved-item', $page, 'a key this shape should draw no card');
    }

    /**
     * And the other half of the loop: what the search card writes.
     *
     * Every test above builds a key by hand, so none of them touches the card
     * that produces one — a mutant that made it write `:Y` for economy
     * survived all of them. That is not harmless: the heart is lit by
     * `indexOf(dataset.flightKey)`, so an economy save from before this would
     * stop being recognised the moment the spelling changed.
     */
    public function testTheSearchCardWritesTheCabinIntoTheKeyOnlyWhenItIsNotEconomy(): void
    {
        $ids = implode('-', $this->inserted);

        self::assertStringContainsString(
            'data-flight-key="' . $ids . ':C"',
            $this->searchPage(CabinClass::Business),
            'a business result should key itself as business',
        );

        self::assertStringContainsString(
            'data-flight-key="' . $ids . '"',
            $this->searchPage(CabinClass::Economy),
            'and an economy result should key itself the way it always has',
        );
    }

    /**
     * The results page for this fixture route in one cabin.
     */
    private function searchPage(CabinClass $cabin): string
    {
        $this->connection();

        $path = new SearchUrl(
            from: self::FROM,
            to: self::TO,
            depart: self::DATE,
            return: null,
            cabin: $cabin,
        )->path();

        Routes::setCurrentPage($path);

        ob_start();

        try {
            new SearchController(new Request(new Input(), new Input(), new Input(), uri: $path))->index();
            $page = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        self::assertStringContainsString('data-flight-key=', $page, 'the fixture route should return a card');

        return $page;
    }

    /**
     * The marker the malformed-key tests look for the absence of, asserted
     * present for a good key -- or those tests pass for the wrong reason.
     */
    public function testAGoodKeyDrawsACard(): void
    {
        self::assertStringContainsString('saved-item', $this->savedPage($this->key()));
    }

    private function key(): string
    {
        return implode('-', $this->inserted);
    }

    /**
     * What the card would print for this itinerary in one cabin: the whole
     * part of the total, formatted the way the page formats it.
     */
    private function priceShown(CabinClass $cabin): string
    {
        $itinerary = new FlightFinder($this->connection())->itinerary($this->inserted, $cabin);

        self::assertNotNull($itinerary, 'the fixture should rebuild as an itinerary');

        return new ItineraryPresenter()
            ->priceParts((float) $itinerary['price_base'] + (float) $itinerary['price_tax'])['whole'];
    }

    /**
     * /my/saved as it renders for one cookie.
     */
    private function savedPage(string $key): string
    {
        $this->connection();

        Routes::setCurrentPage('/my/saved/');

        $cookies = new Input(['tb_saved_flights' => (string) json_encode([$key])]);

        ob_start();

        try {
            new MyController(new Request(new Input(), new Input(), $cookies, uri: '/my/saved/'))->saved();

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }

    private function insertFlight(string $from, string $departure, string $to, string $arrival): int
    {
        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, price_base, price_tax, rating, cabins)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['AC', 100, $from, self::DATE . ' ' . $departure, $to, self::DATE . ' ' . $arrival, 900, 120, 400.00, 60.00, 4.20, 15],
        );
    }
}
