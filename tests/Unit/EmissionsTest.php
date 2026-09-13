<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\CabinClass;
use TripBuilder\Emissions;

/**
 * The model, pinned where it makes a claim.
 *
 * Not the figures -- those are estimates and an estimate asserted to two
 * decimal places is a test of arithmetic, not of anything anyone cares about.
 * What is worth holding still is the shape: that a business seat costs more
 * than an economy one on the same aeroplane, that short hops are worse per
 * kilometre than long ones, and that a type nobody has a burn figure for
 * returns nothing rather than a confident zero (C5, #154).
 */
final class EmissionsTest extends TestCase
{
    // An A320-ish frame: economy, a small business cabin, a published burn.
    private const array SEATS = ['C' => 12, 'Y' => 150];
    private const float BURN = 3.50;

    public function testASeatOnALegHasAFigure(): void
    {
        $kilograms = Emissions::forLeg(1000, self::BURN, self::SEATS, CabinClass::Economy);

        self::assertNotNull($kilograms);
        // Wide on purpose. Published short-haul economy sits near 90 grams per
        // passenger-kilometre; anything outside this is a broken model rather
        // than a different estimate.
        self::assertGreaterThan(50, $kilograms);
        self::assertLessThan(150, $kilograms);
    }

    /**
     * A business seat is the floor of two and a half economy seats.
     */
    public function testTheBiggerSeatCarriesTheBiggerShare(): void
    {
        $economy = Emissions::forLeg(1000, self::BURN, self::SEATS, CabinClass::Economy);
        $business = Emissions::forLeg(1000, self::BURN, self::SEATS, CabinClass::Business);

        self::assertNotNull($economy);
        self::assertNotNull($business);
        self::assertEqualsWithDelta(2.5, $business / $economy, 0.001);
    }

    /**
     * The climb is paid once, so the shorter the leg the worse it reads.
     */
    public function testAShortHopIsWorsePerKilometre(): void
    {
        $short = Emissions::forLeg(400, self::BURN, self::SEATS, CabinClass::Economy);
        $long = Emissions::forLeg(4000, self::BURN, self::SEATS, CabinClass::Economy);

        self::assertNotNull($short);
        self::assertNotNull($long);
        self::assertGreaterThan($long / 4000, $short / 400);
    }

    /**
     * Twice the distance is a little less than twice the carbon, for the same
     * reason.
     */
    public function testDistanceIsNotQuiteProportional(): void
    {
        $one = Emissions::forLeg(1000, self::BURN, self::SEATS, CabinClass::Economy);
        $two = Emissions::forLeg(2000, self::BURN, self::SEATS, CabinClass::Economy);

        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertGreaterThan($one, $two);
        self::assertLessThan($one * 2, $two);
    }

    public function testAThirstierAircraftCostsMore(): void
    {
        $thrifty = Emissions::forLeg(1000, 3.00, self::SEATS, CabinClass::Economy);
        $thirsty = Emissions::forLeg(1000, 4.00, self::SEATS, CabinClass::Economy);

        self::assertNotNull($thrifty);
        self::assertNotNull($thirsty);
        self::assertGreaterThan($thrifty, $thirsty);
    }

    /**
     * More seats to spread the same fuel across is less carbon each.
     */
    public function testAFullerFrameDividesTheFuelFurther(): void
    {
        $dense = Emissions::forLeg(1000, self::BURN, ['Y' => 186], CabinClass::Economy);
        $roomy = Emissions::forLeg(1000, self::BURN, ['Y' => 120], CabinClass::Economy);

        self::assertNotNull($dense);
        self::assertNotNull($roomy);
        self::assertLessThan($roomy, $dense);
    }

    public function testAnUnknownAircraftSaysNothing(): void
    {
        self::assertNull(Emissions::forLeg(1000, 0.0, self::SEATS, CabinClass::Economy));
    }

    public function testAFrameWithNoSeatsSaysNothing(): void
    {
        self::assertNull(Emissions::forLeg(1000, self::BURN, [], CabinClass::Economy));
    }

    /**
     * A cabin this frame does not have fitted has no share to take.
     */
    public function testACabinThatIsNotOnBoardSaysNothing(): void
    {
        self::assertNull(Emissions::forLeg(1000, self::BURN, self::SEATS, CabinClass::First));
    }

    public function testALegWithNoDistanceSaysNothing(): void
    {
        self::assertNull(Emissions::forLeg(0, self::BURN, self::SEATS, CabinClass::Economy));
    }

    /**
     * Burning a kilogram of jet fuel makes 3.16 kilograms of CO2. It is the one
     * number in the model that is chemistry rather than an estimate, so a
     * change to it is a change to every figure on the site.
     */
    public function testTheCombustionFactorIsWhatItIs(): void
    {
        self::assertSame(3.16, Emissions::KG_CO2_PER_KG_FUEL);
    }
}
