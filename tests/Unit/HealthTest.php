<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Health;
use TripBuilder\Routes;

final class HealthTest extends TestCase
{
    public function testAWorkingDatabaseIsTwoHundredAndSaysSo(): void
    {
        self::assertSame(200, Health::statusCode(true));
        self::assertSame(
            ['status' => 'ok', 'db' => 'ok', 'version' => 'v1.2.3-develop-abc1234'],
            Health::report(true, 'v1.2.3-develop-abc1234'),
        );
    }

    /**
     * The answer a live check cannot be asked to produce.
     *
     * `Connection::fromEnv()` shares one connection per process, so there is no
     * way to ask a running application for a broken database. This is why the
     * report is a pure function rather than something assembled inside the
     * controller.
     */
    public function testABrokenDatabaseIsFiveOhThreeAndSaysSo(): void
    {
        self::assertSame(503, Health::statusCode(false));
        self::assertSame(
            ['status' => 'error', 'db' => 'down', 'version' => 'v1.2.3-develop-abc1234'],
            Health::report(false, 'v1.2.3-develop-abc1234'),
        );
    }

    /**
     * 503 and not 500. The application answered; the thing behind it did not,
     * and a load balancer treats those differently.
     */
    public function testAFailingDatabaseIsNotReportedAsAnApplicationError(): void
    {
        self::assertNotSame(500, Health::statusCode(false));
    }

    /**
     * `/health` is routed, and is not a page.
     *
     * Being in EXCLUDE_HEADER_FOOTER does three things at once: no layout
     * wrapped around the JSON, no entry in the sitemap, and no invitation to
     * an indexer -- because `isPublic()` reads the same list.
     */
    public function testHealthIsRoutedAndIsNotAPage(): void
    {
        self::assertSame('Health@index', Routes::resolve('/health'));
        self::assertFalse(Routes::isPublic('/health'), '/health would be in the sitemap');
    }
}
