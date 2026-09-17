<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\CabinClass;
use TripBuilder\Repository\RouteWatchRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * Watching a route for a price: registering, reading a route's own watches
 * back, and the notified-price bookkeeping `alerts:check` relies on.
 */
final class RouteWatchRepositoryTest extends IntegrationTestCase
{
    private const string SENTINEL = 'zzwatch-';
    private const string FROM = 'ZZW';
    private const string TO = 'ZZV';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute(
            'DELETE FROM route_watches WHERE email LIKE ?',
            [self::SENTINEL . '%'],
        );
    }

    private function watches(): RouteWatchRepository
    {
        return new RouteWatchRepository($this->connection());
    }

    private function email(string $suffix): string
    {
        return self::SENTINEL . $suffix . '@example.com';
    }

    public function testSubscribingTwiceUpdatesRatherThanDuplicates(): void
    {
        $email = $this->email('twice');
        $watches = $this->watches();

        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 500.0);
        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 400.0);

        $found = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy);

        self::assertCount(1, $found, 'a second subscribe should update, not add a row');
        self::assertSame(400.0, $found[0]['threshold']);
    }

    /**
     * A changed threshold clears whatever this watch was last notified at --
     * it should be judged fresh against the new number, not silenced by an
     * alert sent for the old one.
     */
    public function testChangingTheThresholdClearsNotifiedPrice(): void
    {
        $email = $this->email('reset');
        $watches = $this->watches();

        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 500.0);
        $id = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['id'];
        $watches->markNotified($id, 450.0);

        $watches->subscribe($email, self::FROM, self::TO, CabinClass::Economy, 300.0);

        $found = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy);

        self::assertSame(300.0, $found[0]['threshold']);
        self::assertNull($found[0]['notified_price']);
    }

    public function testRoutesListsEachWatchedRouteOnce(): void
    {
        $watches = $this->watches();
        $watches->subscribe($this->email('a'), self::FROM, self::TO, CabinClass::Economy, 500.0);
        $watches->subscribe($this->email('b'), self::FROM, self::TO, CabinClass::Economy, 600.0);

        $routes = array_filter(
            $watches->routes(),
            static fn(array $r): bool => $r['from_code'] === self::FROM && $r['to_code'] === self::TO,
        );

        self::assertCount(1, $routes, 'two watches on the same route should be one route to check');
    }

    public function testMarkAndClearNotified(): void
    {
        $watches = $this->watches();
        $watches->subscribe($this->email('mark'), self::FROM, self::TO, CabinClass::Economy, 500.0);
        $id = $watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['id'];

        $watches->markNotified($id, 420.0);
        self::assertSame(420.0, $watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['notified_price']);

        $watches->clearNotified($id);
        self::assertNull($watches->forRoute(self::FROM, self::TO, CabinClass::Economy)[0]['notified_price']);
    }
}
