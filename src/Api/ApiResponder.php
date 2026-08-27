<?php

declare(strict_types=1);

namespace TripBuilder\Api;

class ApiResponder
{
    private static function sendResponse(HttpStatus $status, ?string $message = null): never
    {
        http_response_code($status->value);

        header('Content-type: application/json; charset=utf-8');

        echo json_encode([
            'status' => $status->value,
            'data' => $message ?? $status->phrase(),
        ]);

        die();
    }

    public static function badRequest(?string $message = null): never
    {
        self::sendResponse(HttpStatus::BadRequest, $message);
    }

    public static function unauthorizedAccess(?string $message = null): never
    {
        self::sendResponse(HttpStatus::Unauthorized, $message);
    }

    public static function notFound(?string $message = null): never
    {
        self::sendResponse(HttpStatus::NotFound, $message);
    }

    /**
     * @param HttpMethod[] $allowed
     */
    public static function methodNotAllowed(array $allowed, ?string $message = null): never
    {
        header('Access-Control-Allow-Methods: ' . implode(',', array_map(
            static fn(HttpMethod $method): string => $method->value,
            $allowed,
        )));

        self::sendResponse(HttpStatus::MethodNotAllowed, $message);
    }
}
