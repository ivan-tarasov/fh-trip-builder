<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\View\TwigRenderer;

/**
 * The airline masthead: the mark, who the airline is, and how to reach it.
 *
 * Two of the decisions here are the kind that get undone by somebody being
 * helpful. The address is printed as the address, not as "<name> website", and
 * whatever scheme is stored is the scheme shown -- 6 of the 105 are plain
 * http, and printing https over one of those would be the page claiming
 * something it has not checked.
 *
 * The phone is not a tel: link, which looks like an oversight and is not. One
 * of the 105 numbers carries a country code; the rest are written the way each
 * airline writes them at home, so a tel: built from them dials a wrong number
 * more often than the right one.
 */
final class AirlineMastheadRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides = []): string
    {
        // The overrides first: `+` keeps the left-hand value on a collision,
        // so defaults written first would win and no override would apply.
        return new TwigRenderer()->render('airline/blocks/facts.html.twig', [
            'place' => $overrides + [
                'code' => 'AC',
                'name' => 'Air Canada',
                'country' => 'Canada',
                'country_url' => '/country/canada-ca',
                'hub_count' => 3,
                'destinations' => 228,
                'per_day' => 61,
                'widebody_share' => 54,
                'url' => 'https://www.aircanada.com/ca/en/aco/home.html',
                'phone' => '888-247-2262',
            ],
        ]);
    }

    public function testTheWebsiteIsPrintedAsItsAddress(): void
    {
        $html = $this->render();

        self::assertStringContainsString(
            '>https://www.aircanada.com/ca/en/aco/home.html<',
            $html,
        );
        self::assertStringNotContainsString('Air Canada website', $html);
    }

    /**
     * The scheme shown is the scheme stored, for the 6 that are not https.
     */
    public function testAPlainHttpAddressIsNotDressedUpAsHttps(): void
    {
        $html = $this->render(['url' => 'http://www.tarom.ro/fr/']);

        self::assertStringContainsString('>http://www.tarom.ro/fr/<', $html);
        self::assertStringNotContainsString('https://www.tarom.ro', $html);
    }

    public function testThePhoneIsNotDialled(): void
    {
        $html = $this->render();

        self::assertStringContainsString('888-247-2262', $html);
        self::assertStringNotContainsString('tel:', $html);
    }

    /**
     * The separator belongs between two things, so it needs both.
     */
    public function testTheSeparatorOnlyAppearsBetweenTwoContacts(): void
    {
        $both = $this->render();
        $phoneOnly = $this->render(['url' => '']);
        $neither = $this->render(['url' => '', 'phone' => '']);

        self::assertSame(2, substr_count($both, 'masthead__sep'), 'country and contacts');
        self::assertSame(1, substr_count($phoneOnly, 'masthead__sep'), 'country only');
        self::assertStringNotContainsString('masthead__contact"', $neither);
    }
}
