<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Http\RateLimit;
use TripBuilder\Repository\RateLimitRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The counting, against a real table.
 *
 * A unit test with a fake connection would assert the SQL was assembled, which
 * is the part that was never in doubt. What is worth proving is that the
 * primary key and the upsert behave -- on both engines, which is why this is
 * an integration test and why the statement avoids the row-alias form MariaDB
 * rejects.
 */
final class RateLimitRepositoryTest extends IntegrationTestCase
{
    private const string CLIENT = '198.51.100.7';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM rate_limits WHERE client LIKE ?', ['198.51.100.%']);
    }

    private function limits(): RateLimitRepository
    {
        return new RateLimitRepository($this->connection());
    }

    public function testTheFirstRequestIsAllowed(): void
    {
        self::assertFalse($this->limits()->exceeded(RateLimit::Subscribe, self::CLIENT));
    }

    /**
     * Exactly the stated number, and then no more.
     */
    public function testTheAllowanceIsSpentAndThenRefused(): void
    {
        $limits = $this->limits();

        for ($i = 0; $i < RateLimit::Subscribe->perHour(); $i++) {
            self::assertFalse(
                $limits->exceeded(RateLimit::Subscribe, self::CLIENT),
                sprintf('Request %d of the allowance was refused.', $i + 1),
            );
        }

        self::assertTrue($limits->exceeded(RateLimit::Subscribe, self::CLIENT));
    }

    /**
     * A refused request still counts.
     *
     * Otherwise holding the door open is free: a client that keeps sending
     * would sit at the limit forever instead of being told no for the hour.
     */
    public function testARefusedRequestDoesNotRestoreTheAllowance(): void
    {
        $limits = $this->limits();

        for ($i = 0; $i <= RateLimit::Subscribe->perHour(); $i++) {
            $limits->exceeded(RateLimit::Subscribe, self::CLIENT);
        }

        self::assertTrue($limits->exceeded(RateLimit::Subscribe, self::CLIENT));
    }

    /**
     * One client's spending is not another's, and one scope's is not another's.
     */
    public function testTheCountersAreSeparatePerClientAndPerScope(): void
    {
        $limits = $this->limits();

        for ($i = 0; $i <= RateLimit::Subscribe->perHour(); $i++) {
            $limits->exceeded(RateLimit::Subscribe, self::CLIENT);
        }

        self::assertTrue($limits->exceeded(RateLimit::Subscribe, self::CLIENT));
        self::assertFalse($limits->exceeded(RateLimit::Subscribe, '198.51.100.8'));
        self::assertFalse($limits->exceeded(RateLimit::Vote, self::CLIENT));
    }

    /**
     * Pruning drops finished hours and leaves the one in progress.
     */
    public function testPruningKeepsTheCurrentWindow(): void
    {
        $limits = $this->limits();
        $limits->exceeded(RateLimit::Vote, self::CLIENT);

        $this->connection()->execute(
            'INSERT INTO rate_limits (scope, client, window_start, hits) VALUES (?, ?, ?, ?)',
            [RateLimit::Vote->value, '198.51.100.9', date('Y-m-d H:00:00', strtotime('-2 days')), 5],
        );

        self::assertSame(1, $limits->prune(date('Y-m-d H:i:s', strtotime('-1 day'))));

        /** @var int $count */
        $count = $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM rate_limits WHERE client LIKE ?',
            ['198.51.100.%'],
        );

        self::assertSame(1, $count);
    }
}
