<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

use ReflectionMethod;
use TripBuilder\Api\Flights\FlightSearchQuery;
use TripBuilder\CabinClass;
use TripBuilder\Controllers\CheckoutController;
use TripBuilder\Http\Input;
use TripBuilder\Http\Request;
use TripBuilder\Party;
use TripBuilder\Service\FlightFinder;
use TripBuilder\TripType;

/**
 * The number on the search card and the number on the Pay button, for the same
 * flights and the same party.
 *
 * Everything the flights table holds is one seat. The party multiplier is
 * applied once, in PHP, after the SQL — but "once" happens in two different
 * files: FlightFinder scales what the card shows, and CheckoutController's
 * resolveTrip() scales what the buyer is charged. Nothing but arithmetic keeps
 * those two in step, and when they drift the failure is silent and expensive:
 * a traveller is shown one total and billed another.
 *
 * A mixed party is used throughout because it is the only kind that can catch
 * this. Base and tax carry different shares — a lap infant pays a token fare
 * and no tax at all — so a party of plain adults would agree even if one side
 * summed before scaling and the other scaled before summing.
 */
final class PartyPriceAgreementTest extends IntegrationTestCase
{
    private const DEPART_DATE = '2026-09-15';
    private const RETURN_DATE = '2026-09-22';

    // Deliberately awkward: scaled by a child's 0.75 these land on a half cent,
    // so any difference in where the two sides round shows up in the total.
    private const FIXTURE_BASE = 111.11;
    private const FIXTURE_TAX = 17.77;

    /** @var list<int> */
    private array $fixtures = [];

    private ?int $outboundOne = null;
    private ?int $outboundTwo = null;
    private ?int $returnLeg = null;

    protected function setUp(): void
    {
        // A connection rather than a direct flight: the per-leg rounding the
        // repository does is what makes a two-leg total worth checking.
        $this->outboundOne = $this->insertFlight('AC', 'YUL', self::DEPART_DATE . ' 06:00:00', 'YYZ', self::DEPART_DATE . ' 07:15:00');
        $this->outboundTwo = $this->insertFlight('AC', 'YYZ', self::DEPART_DATE . ' 09:15:00', 'YVR', self::DEPART_DATE . ' 14:30:00');
        $this->returnLeg = $this->insertFlight('AC', 'YVR', self::RETURN_DATE . ' 08:00:00', 'YUL', self::RETURN_DATE . ' 15:40:00');
    }

    protected function tearDown(): void
    {
        if ($this->fixtures !== []) {
            $placeholders = implode(', ', array_fill(0, count($this->fixtures), '?'));
            $this->connection()->execute("DELETE FROM flights WHERE id IN ($placeholders)", $this->fixtures);
            $this->fixtures = [];
        }
    }

    public function testTheCardTotalAndThePayButtonAgreeOnAConnection(): void
    {
        $party = new Party(adults: 2, children: 1, infants: 1);
        $ids = [$this->outboundOne, $this->outboundTwo];

        $card = $this->cardTotalFor($ids, $party);
        $checkout = $this->checkoutTotalFor($ids, [], $party);

        self::assertSame(
            $this->cents($card),
            $this->cents($checkout),
            'the search card and the Pay button disagree on what this party pays',
        );
    }

    public function testTheCardTotalAndThePayButtonAgreeOnARoundTrip(): void
    {
        // The one place the two sides could legitimately differ: a round trip
        // has two directions to add and one multiplier to apply, and adding
        // before scaling is not the same as scaling before adding once a lap
        // infant is in the party.
        $party = new Party(adults: 2, children: 1, infants: 1);
        $outbound = [$this->outboundOne, $this->outboundTwo];
        $return = [$this->returnLeg];

        $package = $this->packageTotalFor($outbound, $return, $party);
        $checkout = $this->checkoutTotalFor($outbound, $return, $party);

        self::assertSame($this->cents($package), $this->cents($checkout));
    }

    public function testASingleAdultIsStillThePerSeatPrice(): void
    {
        // The party layer must be invisible when there is one adult, or every
        // price in the app moved the day it was added.
        $ids = [$this->outboundOne, $this->outboundTwo];

        self::assertSame(
            $this->cents($this->cardTotalFor($ids, new Party())),
            $this->cents($this->checkoutTotalFor($ids, [], new Party())),
        );
    }

    /**
     * What the search card shows for these legs, scaled for this party.
     *
     * @param list<int> $ids
     */
    private function cardTotalFor(array $ids, Party $party): float
    {
        $result = $this->finder()->search($this->query($party), TripType::Oneway);

        foreach ($result['flights'] as $row) {
            $legIds = array_map(
                static fn(array $segment): int => (int) $segment['id'],
                $row['itinerary']['segments'],
            );

            if ($legIds === $ids) {
                return (float) $row['price_base'] + (float) $row['price_tax'];
            }
        }

        self::fail('the fixture itinerary was not among the search results');
    }

    /**
     * The package price the results page shows once both directions are chosen.
     *
     * @param list<int> $outbound
     * @param list<int> $return
     */
    private function packageTotalFor(array $outbound, array $return, Party $party): float
    {
        $result = $this->finder()->search($this->query($party), TripType::Roundtrip, $outbound, $return);

        self::assertNotNull($result['package_price'], 'the package price was not offered');

        return (float) $result['package_price'];
    }

    /**
     * What resolveTrip() hands the Pay button and writes to the booking.
     *
     * Reached through the controller rather than reimplemented here: a copy of
     * its arithmetic would agree with the card forever and prove nothing.
     *
     * @param list<int> $outbound
     * @param list<int> $return
     */
    private function checkoutTotalFor(array $outbound, array $return, Party $party): float
    {
        $controller = new CheckoutController(new Request(
            query: new Input([
                'adults' => (string) $party->adults,
                'children' => (string) $party->children,
                'infants' => (string) $party->infants,
            ]),
            body: new Input(),
            cookies: new Input(),
            method: 'GET',
            uri: '/checkout',
        ));

        $trip = new ReflectionMethod($controller, 'resolveTrip')
            ->invoke($controller, $outbound, $return, CabinClass::Economy);

        self::assertNotNull($trip, 'checkout could not rebuild the trip');

        // raw_base and raw_tax, because those are the numbers that reach the
        // bookings table. The formatted parts beside them are the same money
        // split for display.
        return (float) $trip['raw_base'] + (float) $trip['raw_tax'];
    }

    private function query(Party $party): FlightSearchQuery
    {
        return new FlightSearchQuery(
            offset: 0,
            limit: 50,
            sort: 'price',
            from: 'YUL',
            to: 'YVR',
            departDate: self::DEPART_DATE,
            returnDate: self::RETURN_DATE,
            party: $party,
            cabin: CabinClass::Economy,
        );
    }

    /**
     * Money compared as whole cents: two floats that render identically can
     * still fail an identity check, and the cent is the unit that is charged.
     */
    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function finder(): FlightFinder
    {
        return new FlightFinder($this->connection());
    }

    private function insertFlight(string $airline, string $from, string $departure, string $to, string $arrival): int
    {
        $id = $this->connection()->insert(
            'INSERT INTO flights (airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, cabins, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$airline, 100, $from, $departure, $to, $arrival, 504, 75, 1, self::FIXTURE_BASE, self::FIXTURE_TAX, 4.10],
        );

        $this->fixtures[] = $id;

        return $id;
    }
}
