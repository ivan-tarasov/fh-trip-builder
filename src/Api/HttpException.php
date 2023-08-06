<?php

namespace TripBuilder\Api;

class HttpException
{
    /**
     * @var array Standard HTTP reason phrases
     * @link https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
     */
    const STATUS_CODE_PHRASES = [
        // 1xx informational response
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',

        // 2xx success
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        226 => 'IM Used',

        // 3xx redirection
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',

        // 4xx client errors
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I’m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',

        // 5xx server errors
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        511 => 'Network Authentication Required',
    ];

    private static function sendResponse(int $statusCode, $message = null)
    {
        http_response_code($statusCode);

        header('Content-type: application/json; charset=utf-8');

        echo json_encode([
            'status' => $statusCode,
            'data'   => $message ?? self::STATUS_CODE_PHRASES[$statusCode],
        ]);

        die();
    }

    /**
     * @param string|null $message
     * @return void
     */
    public static function unauthorizedAccess(string $message = null): void
    {
        self::sendResponse(401, $message);
    }

    /**
     * @param string|null $message
     * @return void
     */
    public static function notFound(string $message = null): void
    {
        self::sendResponse(404, $message);
    }

    /**
     * @param array       $allowed
     * @param string|null $message
     * @return void
     */
    public static function methodNotAllowed(array $allowed, string $message = null): void
    {
        header('Access-Control-Allow-Methods: ' . implode(',', $allowed));

        self::sendResponse(405, $message);
    }

}