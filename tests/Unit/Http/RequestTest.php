<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TripBuilder\Http\Input;
use TripBuilder\Http\Request;

/**
 * Built from arrays, with no superglobals to set up or restore. That is the
 * whole reason the request is passed in rather than reached for.
 */
final class RequestTest extends TestCase
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    private function request(string $uri = '/', string $method = 'GET', array $query = [], array $headers = []): Request
    {
        return new Request(
            query: new Input($query),
            body: new Input(),
            cookies: new Input(),
            method: $method,
            uri: $uri,
            headers: $headers,
        );
    }

    public function testPathDropsTheQueryStringAndTrailingSlash(): void
    {
        self::assertSame('/', $this->request('/')->path());
        self::assertSame('/', $this->request('/?a=1')->path());
        self::assertSame('/search', $this->request('/search')->path());
        self::assertSame('/search', $this->request('/search/')->path());
        self::assertSame('/search', $this->request('/search/?from=LON&to=NYC')->path());
        self::assertSame('/api/airports/autofill', $this->request('/api/airports/autofill/')->path());
    }

    public function testPathIsTheOneAnswerTheRouterAndTheFormsShare(): void
    {
        // These used to be computed two ways -- the router trimmed the trailing
        // slash and Helper::getUrlPath() did not -- so a request to
        // /api/airports/autofill/ routed one way and failed the endpoint
        // comparison that exempts it from the auth check.
        $trailing = $this->request('/api/airports/autofill/');
        $bare = $this->request('/api/airports/autofill');

        self::assertSame($bare->path(), $trailing->path());
    }

    public function testMethodIsNormalisedAndPostIsRecognised(): void
    {
        self::assertTrue($this->request('/', 'POST')->isPost());
        self::assertFalse($this->request('/', 'GET')->isPost());
        self::assertSame('POST', $this->request('/', 'POST')->method());
    }

    public function testFragmentIsDecidedInOnePlace(): void
    {
        // index.php and SearchController each decided this for themselves.
        self::assertTrue($this->request('/search', 'GET', ['fragment' => '1'])->isFragment());
        self::assertFalse($this->request('/search', 'GET', ['fragment' => '0'])->isFragment());
        self::assertFalse($this->request('/search', 'GET', ['fragment' => 'true'])->isFragment());
        self::assertFalse($this->request('/search')->isFragment());
    }

    public function testHeadersAreReadByTheirOrdinaryName(): void
    {
        $request = $this->request('/', 'POST', [], [
            'x-csrf-token' => 'abc123',
            'content-type' => 'application/json',
        ]);

        self::assertSame('abc123', $request->header('X-CSRF-Token'));
        self::assertSame('abc123', $request->header('x-csrf-token'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertNull($request->header('X-Absent'));
    }

    public function testCaptureReadsTheServerArrayIntoHeaders(): void
    {
        $get = $_GET;
        $server = $_SERVER;

        try {
            $_GET = ['fragment' => '1'];
            $_SERVER = [
                'REQUEST_METHOD' => 'post',
                'REQUEST_URI' => '/search/?fragment=1',
                'HTTP_X_CSRF_TOKEN' => 'tok',
                'CONTENT_TYPE' => 'application/json',
                'HTTPS' => 'on',
            ];

            $request = Request::capture();

            self::assertSame('POST', $request->method(), 'the method is upper-cased');
            self::assertSame('/search', $request->path());
            self::assertTrue($request->isFragment());
            self::assertTrue($request->isSecure());
            self::assertSame('tok', $request->header('X-CSRF-Token'));
            self::assertSame('application/json', $request->header('Content-Type'));
        } finally {
            $_GET = $get;
            $_SERVER = $server;
        }
    }
}
