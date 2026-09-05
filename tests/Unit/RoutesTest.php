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

    public function testABookingIsAddressedByItsIdInThePath(): void
    {
        self::assertSame('My@booking', Routes::resolve('/my/bookings/100001'));
        self::assertSame('My@calendar', Routes::resolve('/my/bookings/100001/calendar'));
    }

    public function testASearchIsAddressedByItsOwnPath(): void
    {
        self::assertSame('Search@index', Routes::resolve('/search/YUL1609LHRY1'));
        self::assertSame('Search@index', Routes::resolve('/search/YUL1609LHR3009W421'));
        // The digit-bearing airport code, which [A-Z]{3} would reject.
        self::assertSame('Search@index', Routes::resolve('/search/A391609A39W1'));
        // The query-string form still answers; it is what redirects here.
        self::assertSame('Search@index', Routes::resolve('/search'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function notRoutes(): array
    {
        return [
            ['/search/YUL1609LHR'],
            ['/search/YUL1609LHRY'],
            ['/search/yul1609lhry1'],
            ['/search/YUL1609LHRY1234'],
            ['/my/bookings/abc'],
            ['/my/bookings/'],
            ['/my/bookings/12/'],
            ['/my/bookings/12/calendar/extra'],
            ['/my/bookings/12/cancel'],
            ['/nope'],
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
