<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Health;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Routes;

final class HealthTest extends TestCase
{
    public function testAWorkingDatabaseIsTwoHundredAndSaysSo(): void
    {
        self::assertSame(HttpStatus::Ok, Health::statusCode(true));
        self::assertSame(
            [
                'status' => 'ok',
                'db' => 'ok',
                'clock' => 'unknown',
                'schedule' => 'unknown',
                'tasks' => [],
                'version' => 'v1.2.3-develop-abc1234',
            ],
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
        self::assertSame(HttpStatus::ServiceUnavailable, Health::statusCode(false));
        self::assertSame(
            [
                'status' => 'error',
                'db' => 'down',
                'clock' => 'unknown',
                'schedule' => 'unknown',
                'tasks' => [],
                'version' => 'v1.2.3-develop-abc1234',
            ],
            Health::report(false, 'v1.2.3-develop-abc1234'),
        );
    }

    /**
     * 503 and not 500. The application answered; the thing behind it did not,
     * and a load balancer treats those differently.
     */
    public function testAFailingDatabaseIsNotReportedAsAnApplicationError(): void
    {
        self::assertNotSame(HttpStatus::InternalServerError, Health::statusCode(false));
    }

    /**
     * A stale schedule does not make the site look down.
     *
     * Rates being two days old is not a reason to tell a load balancer to stop
     * sending traffic, and a check that cannot tell those apart is a check that
     * gets muted. So it is its own field.
     */
    public function testAStaleScheduleIsReportedWithoutFailingTheCheck(): void
    {
        $report = Health::report(true, 'v1', [
            'currency:rates' => ['age' => '2d', 'stale' => true],
            'db:prune --force' => ['age' => '3h', 'stale' => false],
        ]);

        self::assertSame('stale', $report['schedule']);
        self::assertSame('ok', $report['status'], 'a stale cron made the site look down');
        self::assertSame(HttpStatus::Ok, Health::statusCode(true));
        self::assertSame(['currency:rates' => '2d', 'db:prune --force' => '3h'], $report['tasks']);
    }

    public function testAHealthyScheduleSaysSo(): void
    {
        $report = Health::report(true, 'v1', ['currency:rates' => ['age' => '3h', 'stale' => false]]);

        self::assertSame('ok', $report['schedule']);
    }

    /**
     * With no records to read, the answer is "unknown" and not "ok".
     *
     * Reporting ok would be reporting on a question that was never asked, and
     * that is the one thing a health endpoint must not do.
     */
    public function testAnUnreadableScheduleIsNotReportedAsHealthy(): void
    {
        self::assertSame('unknown', Health::report(true, 'v1', [])['schedule']);
    }

    /**
     * A database on a different clock does not make the site look down.
     *
     * Same reasoning as a stale schedule: it is a thing that is true and wrong
     * at once, and a check that cannot tell it from unreachable gets muted.
     */
    public function testAClockDisagreementIsReportedWithoutFailingTheCheck(): void
    {
        $report = Health::report(true, 'v1', [], -14400);

        self::assertSame('4h behind', $report['clock']);
        self::assertSame('ok', $report['status'], 'a timezone difference made the site look down');
        self::assertSame(HttpStatus::Ok, Health::statusCode(true));
    }

    public function testAgreeingClocksSayOk(): void
    {
        self::assertSame('ok', Health::report(true, 'v1', [], 0)['clock']);
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
