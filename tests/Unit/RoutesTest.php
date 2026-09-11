<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Routes;

final class RoutesTest extends TestCase
{
    public function testAnExactRouteStillResolves(): void
    {
        self::assertSame('My@bookings', Routes::resolve('/my/bookings'));
        self::assertSame('Checkout@confirmation', Routes::resolve('/checkout/confirmation'));
    }

    /**
     * The hub is an exact route and the articles are a pattern.
     *
     * The pattern is the loose half, and it is loose deliberately: what makes a
     * slug real is being a row in `articles`, which is
     * HelpController's question and not the router's. So the router says yes to
     * a word here and the page still answers 404.
     */
    public function testHelpHasAHubAndAPatternForItsArticles(): void
    {
        self::assertSame('Help@index', Routes::resolve('/help'));
        self::assertSame('Help@show', Routes::resolve('/help/baggage'));
        self::assertSame('Help@show', Routes::resolve('/help/ticket-not-received'));
        // Capitals reach the controller so that it can redirect them.
        self::assertSame('Help@show', Routes::resolve('/help/Baggage'));
    }

    /**
     * Airside allows digits in a slug where help does not.
     *
     * A help article is a subject; a post is a piece of writing, and
     * "three-ways-to-pick-a-seat" is a title somebody will write. The pattern,
     * `AirsideController::slug()` and the check `airside:import` makes on a
     * file name are three copies of this rule, so a slug that stops matching
     * one of them stops being reachable.
     */
    public function testAirsideHasAHubAndAPatternThatTakesDigits(): void
    {
        self::assertSame('Airside@index', Routes::resolve('/airside'));
        self::assertSame('Airside@show', Routes::resolve('/airside/picking-a-seat'));
        self::assertSame('Airside@show', Routes::resolve('/airside/three-ways-to-pick-a-seat'));

        // Matched and then 301'd to lower case by the controller, the way help
        // handles a capital.
        self::assertSame('Airside@show', Routes::resolve('/airside/Picking-A-Seat'));

        // Not a post: a slash below the section is not a route here, and
        // neither is an empty slug.
        self::assertNull(Routes::resolve('/airside/picking/a-seat'));
        self::assertNull(Routes::resolve('/airside/'));
    }

    /**
     * A tag's page sits under the section, and is lower case only.
     *
     * Unlike a post slug, which the controller 301s from capitals because a
     * reader may well have typed one: a tag slug is derived from its name by
     * `airside:import`, which lower-cases it, so there is no other spelling in
     * existence to redirect from.
     */
    public function testATagHasItsOwnPageBeneathTheSection(): void
    {
        self::assertSame('Airside@tag', Routes::resolve('/airside/tag/security'));
        self::assertSame('Airside@tag', Routes::resolve('/airside/tag/hand-luggage'));

        self::assertNull(Routes::resolve('/airside/tag/Security'));
        self::assertNull(Routes::resolve('/airside/tag/a/b'));

        // The bare prefix is not a listing. It matches the post pattern, and
        // answers the way any other unknown slug does.
        self::assertSame('Airside@show', Routes::resolve('/airside/tag'));
    }

    public function testABookingIsAddressedByItsIdInThePath(): void
    {
        self::assertSame('My@booking', Routes::resolve('/my/bookings/100001'));
        self::assertSame('My@calendar', Routes::resolve('/my/bookings/100001/calendar'));
    }

    public function testASearchIsAddressedByItsOwnPath(): void
    {
        self::assertSame('Search@index', Routes::resolve('/search/YUL160926LHRY1'));
        self::assertSame('Search@index', Routes::resolve('/search/YUL160926LHR300926W421'));
        // The digit-bearing airport code, which [A-Z]{3} would reject.
        self::assertSame('Search@index', Routes::resolve('/search/A39160926A39W1'));
        // The query-string form still answers; it is what redirects here.
        self::assertSame('Search@index', Routes::resolve('/search'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function notRoutes(): array
    {
        return [
            ['/search/YUL160926LHR'],
            ['/search/YUL160926LHRY'],
            ['/search/yul160926lhry1'],
            ['/search/YUL160926LHRY1234'],
            ['/my/bookings/abc'],
            ['/my/bookings/'],
            ['/my/bookings/12/'],
            ['/my/bookings/12/calendar/extra'],
            ['/my/bookings/12/cancel'],
            ['/nope'],
            // The help pattern is anchored, and takes no digits and no second
            // segment.
            ['/help/'],
            ['/help/baggage/extra'],
            ['/help/baggage2'],
        ];
    }

    #[DataProvider('notRoutes')]
    public function testAnythingElseIsNotARoute(string $url): void
    {
        // The patterns are anchored and digits-only on purpose: a router this
        // small should refuse everything it was not asked to serve.
        self::assertNull(Routes::resolve($url));
    }

    public function testOnlyTheCalendarWritesItsOwnBytes(): void
    {
        // It hangs off a page controller, so the per-controller list cannot
        // cover it -- and a header and footer glued to a download corrupts it.
        self::assertTrue(Routes::emitsOwnPayload('/my/bookings/100001/calendar'));
        self::assertFalse(Routes::emitsOwnPayload('/my/bookings/100001'));
        self::assertFalse(Routes::emitsOwnPayload('/my/bookings'));
        self::assertNotContains('My', Routes::EXCLUDE_HEADER_FOOTER);
    }

    public function testEveryDynamicRouteIsAValidPatternNamingAnAction(): void
    {
        foreach (Routes::DYNAMIC_ROUTES as $pattern => $route) {
            // false, not 0: a malformed pattern would make resolve() warn and
            // fall through to a 404 on a route that is supposed to work.
            self::assertNotFalse(@preg_match($pattern, '/probe'), 'invalid pattern: ' . $pattern);
            self::assertStringContainsString('@', $route, $pattern . ' names no action');
        }
    }
}
