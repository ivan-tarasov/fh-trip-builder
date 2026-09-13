<?php

declare(strict_types=1);

namespace TripBuilder\Api;

use TripBuilder\Http\HttpStatus;

/**
 * How an endpoint says no.
 *
 * The four methods below read as they always did and are called in the same
 * seven places; what changed is that they throw rather than `die()` -- see
 * ApiRefusal for why. `send()` is the other half, and lives here so the
 * envelope a refusal is written in stays next to the refusals themselves.
 *
 * That envelope is deliberately not `AbstractApi::sendResponse()`'s. A refusal
 * carries a status and a reason; a success carries the endpoint, the method and
 * a timestamp around its data. Nothing is gained by making the two one shape
 * except a reason to add empty fields to an error.
 */
class ApiResponder
{
    /**
     * @param array<string, string> $headers
     * @throws ApiRefusal
     */
    private static function refuse(HttpStatus $status, ?string $message, array $headers = []): never
    {
        throw new ApiRefusal($status, $message ?? $status->phrase(), $headers);
    }

    /** @throws ApiRefusal */
    public static function badRequest(?string $message = null): never
    {
        self::refuse(HttpStatus::BadRequest, $message);
    }

    /** @throws ApiRefusal */
    public static function unauthorizedAccess(?string $message = null): never
    {
        self::refuse(HttpStatus::Unauthorized, $message);
    }

    /** @throws ApiRefusal */
    public static function notFound(?string $message = null): never
    {
        self::refuse(HttpStatus::NotFound, $message);
    }

    /**
     * @param HttpMethod[] $allowed
     * @throws ApiRefusal
     */
    public static function methodNotAllowed(array $allowed, ?string $message = null): never
    {
        self::refuse(HttpStatus::MethodNotAllowed, $message, [
            'Access-Control-Allow-Methods' => implode(',', array_map(
                static fn(HttpMethod $method): string => $method->value,
                $allowed,
            )),
        ]);
    }

    /** Write a refusal as the response. */
    public static function send(ApiRefusal $refusal): void
    {
        http_response_code($refusal->status->value);

        header('Content-type: application/json; charset=utf-8');

        foreach ($refusal->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode([
            'status' => $refusal->status->value,
            'data' => $refusal->getMessage(),
        ]);
    }
}
