<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * What the health endpoint says, decided away from the request.
 *
 * A pure function of what was measured, so both answers can be tested -- the
 * failing one especially, which is the answer that matters and the one a live
 * check cannot produce on demand: `Connection::fromEnv()` shares one connection
 * per process, so there is no way to ask a working application for a broken
 * database.
 */
final readonly class Health
{
    /**
     * @return array{status: string, db: string, version: string}
     */
    public static function report(bool $database, string $version): array
    {
        return [
            'status' => $database ? 'ok' : 'error',
            'db' => $database ? 'ok' : 'down',
            'version' => $version,
        ];
    }

    /**
     * `503` and not `500`: the application is answering, the thing behind it is
     * not, and that is a difference a load balancer acts on.
     */
    public static function statusCode(bool $database): int
    {
        return $database ? 200 : 503;
    }
}
