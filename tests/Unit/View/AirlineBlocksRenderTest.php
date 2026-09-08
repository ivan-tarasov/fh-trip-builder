<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\View\TwigRenderer;

/**
 * The two blocks added to the airline page, and the one word that keeps the
 * aircraft list honest.
 *
 * The page has no idea how many aircraft an airline owns. It knows how many of
 * its departures in the next fortnight are flown by each type, which is a
 * different fact -- and a bare "27" beside "Airbus A320" would be read as the
 * first. So every count says "flights", and the block says what fortnight.
 */
final class AirlineBlocksRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    /** @param list<array<string, mixed>> $aircraft */
    private function aircraft(array $aircraft): string
    {
        return new TwigRenderer()->render('airline/blocks/aircraft.html.twig', [
            'aircraft' => $aircraft,
            'window_days' => 14,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function twoTypes(): array
    {
        return [
            ['title' => 'Airbus A380-800', 'manufacturer' => 'Airbus', 'is_widebody' => 1, 'flights' => 1434],
            ['title' => 'ATR 72', 'manufacturer' => 'ATR', 'is_widebody' => 0, 'flights' => 607],
        ];
    }

    /**
     * A count of flights, said as one.
     *
     * The reference this block was modelled on prints a count of airframes.
     * This one cannot, and the difference has to survive somebody tidying the
     * markup.
     */
    public function testEveryCountIsLabelledAsFlights(): void
    {
        $html = $this->aircraft(self::twoTypes());

        self::assertSame(2, substr_count($html, ' flights</span>'));
        self::assertStringContainsString('1,434 flights', $html);
        self::assertStringContainsString('607 flights', $html);
    }

    /**
     * And a period to count them over, or the number means nothing.
     */
    public function testTheBlockSaysHowFarAheadItLooked(): void
    {
        self::assertStringContainsString('next 14 days', $this->aircraft(self::twoTypes()));
    }

    /**
     * Widebodies marked, narrowbodies not.
     *
     * The one attribute a passenger feels, and what makes a short-haul
     * airline's list look different from a long-haul one's at a glance.
     */
    public function testOnlyWidebodiesAreMarked(): void
    {
        $html = $this->aircraft(self::twoTypes());

        self::assertSame(1, substr_count($html, 'chip--wide'));
        self::assertMatchesRegularExpression('#chip chip--wide.*?Airbus A380-800#s', $html);
    }

    /**
     * The other airlines each reach their own page, and say how present they
     * are at the airports this one flies from.
     */
    public function testPeersLinkToTheirOwnPagesWithTheirCount(): void
    {
        $html = new TwigRenderer()->render('airline/blocks/peers.html.twig', [
            'peers' => [
                ['code' => 'WS', 'name' => 'WestJet', 'flights' => 1578, 'url' => '/airline/westjet-ws'],
            ],
            'window_days' => 14,
        ]);

        self::assertStringContainsString('href="/airline/westjet-ws"', $html);
        self::assertStringContainsString('suppliers/WS.png', $html);
        self::assertStringContainsString('1,578 flights', $html);
    }
}
