<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\View\Breadcrumbs;

final class BreadcrumbsTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    /**
     * @param list<array{label: string, url: string|null, current: bool}> $trail
     * @return list<string>
     */
    private static function labels(array $trail): array
    {
        return array_column($trail, 'label');
    }

    public function testANestedPageWalksUpToItsNamedAncestors(): void
    {
        $trail = Breadcrumbs::trail('/my/bookings/100001');

        // /my is a path segment, not a page, so it does not become a crumb.
        self::assertSame(['Home', 'My bookings', '100001'], self::labels($trail));
        self::assertSame(['/', '/my/bookings', null], array_column($trail, 'url'));
    }

    public function testOnlyTheLastCrumbIsCurrent(): void
    {
        $trail = Breadcrumbs::trail('/my/bookings/100001');

        self::assertSame([false, false, true], array_column($trail, 'current'));
        // The current page is not a link to itself.
        self::assertNull($trail[count($trail) - 1]['url']);
    }

    public function testASuppliedLabelNamesThePage(): void
    {
        $trail = Breadcrumbs::trail('/my/bookings/100001', 'L6M5E7');

        self::assertSame(['Home', 'My bookings', 'L6M5E7'], self::labels($trail));
    }

    public function testATopLevelPageGetsATwoCrumbTrail(): void
    {
        self::assertSame(['Home', 'My bookings'], self::labels(Breadcrumbs::trail('/my/bookings')));
        self::assertSame(['Home', 'Airlines'], self::labels(Breadcrumbs::trail('/airlines')));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function withoutTrails(): array
    {
        return [
            // Home is the root of every trail and shows none of its own.
            ['/'],
            // The funnel carries a step indicator instead. This is the test
            // that proves it opts out, and it opts out by being absent from
            // the map rather than by a rule written somewhere.
            ['/search'],
            ['/checkout'],
            ['/checkout/confirmation'],
            // A segment that is not a page in its own right.
            ['/my'],
            // Nothing the map knows about, so nothing to invent.
            ['/nonsense'],
            ['/my/nonsense/deeper'],
        ];
    }

    #[DataProvider('withoutTrails')]
    public function testPagesOutsideTheMapGetNoTrail(string $path): void
    {
        self::assertSame([], Breadcrumbs::trail($path));
    }

    public function testATrailingSlashChangesNothing(): void
    {
        // Request::path() strips it, but this is also called by hand.
        self::assertSame(
            Breadcrumbs::trail('/my/bookings'),
            Breadcrumbs::trail('/my/bookings/'),
        );
        self::assertSame([], Breadcrumbs::trail('//'));
    }

    public function testASectionCoversItsOwnPageAndItsChildren(): void
    {
        // What the nav highlight asks: "My bookings" should stay lit while you
        // are looking at one booking.
        self::assertTrue(Breadcrumbs::covers('/my/bookings', '/my/bookings'));
        self::assertTrue(Breadcrumbs::covers('/my/bookings', '/my/bookings/100001'));
        self::assertTrue(Breadcrumbs::covers('/my/bookings/', '/my/bookings/100001'));
    }

    public function testASectionDoesNotCoverASiblingThatMerelyStartsTheSame(): void
    {
        // The separator is what makes this true; a bare str_starts_with would
        // light up Airports while you are on Airlines' neighbour.
        self::assertFalse(Breadcrumbs::covers('/airport', '/airports'));
        self::assertFalse(Breadcrumbs::covers('/my/booking', '/my/bookings'));
        self::assertFalse(Breadcrumbs::covers('/my/saved', '/my/bookings'));
    }

    public function testRootCoversOnlyItself(): void
    {
        // Otherwise every nav item's ancestor check would match every page.
        self::assertTrue(Breadcrumbs::covers('/', '/'));
        self::assertFalse(Breadcrumbs::covers('/', '/my/bookings'));
    }

    public function testEveryConfiguredPageResolvesToATrail(): void
    {
        // Guards the map against an entry that never renders -- a path typed
        // with a trailing slash, or one that no route serves.
        foreach (array_keys((array) Config::get('breadcrumbs.pages')) as $path) {
            self::assertNotSame([], Breadcrumbs::trail((string) $path), $path . ' yields no trail');
        }
    }
}
