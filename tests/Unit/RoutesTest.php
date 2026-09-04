<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Routes;

final class RoutesTest extends TestCase
{
    public function testEveryRawRouteIsARouteThatExists(): void
    {
        // The list is matched against the request path in index.php, so a
        // renamed or removed route leaves a dead entry that silently stops
        // excluding anything -- and the download it names starts arriving with
        // a page header and footer glued to it.
        foreach (Routes::EXCLUDE_HEADER_FOOTER_ROUTES as $route) {
            self::assertArrayHasKey($route, Routes::ENABLED_ROUTES, $route . ' is not a route');
        }
    }

    public function testTheCalendarDownloadIsExcludedFromTheLayout(): void
    {
        // It hangs off a page controller, so the per-controller list cannot
        // cover it.
        self::assertContains('/my/booking/calendar', Routes::EXCLUDE_HEADER_FOOTER_ROUTES);
        self::assertNotContains('My', Routes::EXCLUDE_HEADER_FOOTER);
    }
}
