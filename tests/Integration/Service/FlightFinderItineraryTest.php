<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Service;

use TripBuilder\Service\FlightFinder;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * FlightFinder::itinerary() rebuilds a saved flight from nothing but its leg
 * ids. The saved-flights page has no other source of truth — it stores ids in a
 * cookie and asks for the itinerary back — so what this returns for ids that no
 * longer make sense decides whether that page renders or breaks.
 */
final class FlightFinderItineraryTest extends IntegrationTestCase
{
    private const DEPART_DATE = '2026-09-15';

    private ?int $legOne = null;
    private ?int $legTwo = null;
    private ?int $unrelated = null;

    protected function setUp(): void
    {
        // YUL -> YYZ -> YUL: two legs that chain, with a 2h connection.
        $this->legOne = $this->insertFlight('AC', 'YUL', self::DEPART_DATE . ' 06:00:00', 'YYZ', self::DEPART_DATE . ' 07:15:00');
        $this->legTwo = $this->insertFlight('AC', 'YYZ', self::DEPART_DATE . ' 09:15:00', 'YUL', self::DEPART_DATE . ' 10:30:00');
        // Departs somewhere the first leg never lands.
        $this->unrelated = $this->insertFlight('WS', 'YYC', self::DEPART_DATE . ' 09:15:00', 'YUL', self::DEPART_DATE . ' 12:00:00');
    }

    protected function tearDown(): void
    {
        $ids = array_values(array_filter([$this->legOne, $this->legTwo, $this->unrelated]));

        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $this->connection()->execute("DELETE FROM flights WHERE id IN ($placeholders)", $ids);
        }
    }

    public function testRebuildsADirectItineraryFromItsOnlyLeg(): void
    {
        $result = $this->finder()->itinerary([$this->legOne]);

        self::assertNotNull($result);
        self::assertSame(0, $result['itinerary']['stops']);
        self::assertCount(1, $result['itinerary']['segments']);
        self::assertSame([], $result['itinerary']['layovers']);
        self::assertGreaterThan(0.0, $result['price_base']);
    }

    public function testRebuildsAConnectionWithItsLayover(): void
    {
        $result = $this->finder()->itinerary([$this->legOne, $this->legTwo]);

        self::assertNotNull($result);
        self::assertSame(1, $result['itinerary']['stops']);
        self::assertCount(2, $result['itinerary']['segments']);

        $layovers = $result['itinerary']['layovers'];
        self::assertCount(1, $layovers);
        self::assertSame('YYZ', $layovers[0]['airport_code']);
        // 07:15 on the ground until 09:15 back in the air.
        self::assertSame(120, $layovers[0]['wait_minutes']);
    }

    public function testPriceIsTheSumOfTheLegs(): void
    {
        $one = $this->finder()->itinerary([$this->legOne]);
        $both = $this->finder()->itinerary([$this->legOne, $this->legTwo]);

        self::assertNotNull($one);
        self::assertNotNull($both);
        self::assertGreaterThan($one['price_base'], $both['price_base']);
    }

    public function testDurationCountsFlyingPlusWaiting(): void
    {
        // Never arrival minus departure: leg stamps are local to their own
        // airports, so subtracting them across timezones inflates the total.
        $result = $this->finder()->itinerary([$this->legOne, $this->legTwo]);

        self::assertNotNull($result);
        // 75m in the air + 120m on the ground + 75m in the air.
        self::assertSame(270, $result['itinerary']['total_duration']);
    }

    public function testReturnsNullWhenALegNoLongerExists(): void
    {
        // A saved flight outlives the data it points at — the page must be able
        // to tell, and drop the card rather than render half an itinerary.
        self::assertNull($this->finder()->itinerary([$this->legOne, 2000000099]));
        self::assertNull($this->finder()->itinerary([2000000099]));
    }

    public function testReturnsNullWhenTheLegsDoNotChain(): void
    {
        // Leg two departs YYC; leg one lands at YYZ. Not an itinerary, however
        // the ids were assembled — a hand-edited cookie included.
        self::assertNull($this->finder()->itinerary([$this->legOne, $this->unrelated]));
    }

    public function testReturnsNullForNoIdsAtAll(): void
    {
        self::assertNull($this->finder()->itinerary([]));
    }

    private function finder(): FlightFinder
    {
        return new FlightFinder($this->connection());
    }

    private function insertFlight(string $airline, string $from, string $departure, string $to, string $arrival): int
    {
        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$airline, 100, $from, $departure, $to, $arrival, 504, 75, 20.00, 3.00, 4.10],
        );
    }
}
