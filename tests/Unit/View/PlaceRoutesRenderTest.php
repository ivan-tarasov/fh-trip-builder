<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\View\TwigRenderer;

/**
 * The routes block on a city or airport page.
 *
 * The block exists to be linked from, so what it is tested for is the link.
 * Until it was added, nothing on the site linked a route page except the
 * footer's five, and 153 of the 158 were pages only the sitemap mentioned.
 *
 * The words in the link are the part worth pinning down. Each chip says both
 * cities, in order -- "Paris to Montreal" and not "Paris" -- because that is
 * what the page it opens is called and what somebody types to look for it.
 * Shortened to the far end the block would still work and would still look
 * right, which is exactly why it needs a test to say otherwise.
 */
final class PlaceRoutesRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    /**
     * Rows in the shape RouteRepository returns them, plus the `url` the
     * controllers add.
     *
     * @param list<array<string, string>> $routes
     */
    private function render(array $routes): string
    {
        return new TwigRenderer()->render('place/routes.html.twig', ['routes' => $routes]);
    }

    /** @return list<array<string, string>> */
    private static function twoRoutes(): array
    {
        return [
            ['from_name' => 'New York', 'to_name' => 'London', 'url' => '/route/new-york-to-london'],
            ['from_name' => 'Paris', 'to_name' => 'London', 'url' => '/route/paris-to-london'],
        ];
    }

    public function testEachRouteBecomesALinkToItsOwnPage(): void
    {
        $html = $this->render(self::twoRoutes());

        self::assertStringContainsString('href="/route/new-york-to-london"', $html);
        self::assertStringContainsString('href="/route/paris-to-london"', $html);
        self::assertSame(2, substr_count($html, '<a class="chip chip--link"'));
    }

    /**
     * Both cities, in the order they are flown.
     *
     * Matched inside the anchor rather than anywhere in the markup, so a chip
     * that said only "New York" could not pass on the strength of the href
     * spelling the pair out.
     */
    public function testTheLinkTextNamesBothCitiesInOrder(): void
    {
        $html = $this->render(self::twoRoutes());

        self::assertMatchesRegularExpression(
            '#<a class="chip chip--link" href="/route/new-york-to-london">.*?New York to London.*?</a>#s',
            $html,
        );
    }

    public function testNothingIsDrawnForAPlaceWithNoRoutes(): void
    {
        self::assertStringNotContainsString('<a', $this->render([]));
    }

    /**
     * A name is data, printed as data.
     *
     * No city in this table needs it today -- checked, none of the 231 holds an
     * apostrophe or an ampersand -- which is the reason to assert it rather
     * than to trust it. Nothing about the block would look wrong on the day a
     * seed adds one.
     */
    public function testNamesAreEscaped(): void
    {
        $html = $this->render([
            ['from_name' => "Ma'an & Co", 'to_name' => 'London', 'url' => '/route/x-to-london'],
        ]);

        self::assertStringContainsString('Ma&#039;an &amp; Co to London', $html);
    }
}
