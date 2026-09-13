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

    /**
     * `Authorization` reaches PHP only as a rewrite's environment variable,
     * and an internal redirect prefixes it.
     *
     * Apache hands the header to CGI/FPM only when told to, so `.htaccess`
     * copies it into `HTTP_AUTHORIZATION` with a RewriteRule — and the
     * front-controller rewrite that follows turns that into
     * `REDIRECT_HTTP_AUTHORIZATION`. Until this read both, every authenticated
     * call to /api/* answered 401 for every client (E28, #207).
     */
    public function testCaptureReadsAHeaderARewriteHadToPutThere(): void
    {
        $server = $_SERVER;

        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/api/airports',
                'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer from-the-rewrite',
            ];

            self::assertSame('Bearer from-the-rewrite', Request::capture()->header('Authorization'));
        } finally {
            $_SERVER = $server;
        }
    }

    /**
     * Two rewrites means two prefixes, and this deployment produces both.
     */
    public function testAHeaderSurvivesMoreThanOneRedirect(): void
    {
        $server = $_SERVER;

        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/api/airports',
                'REDIRECT_REDIRECT_HTTP_AUTHORIZATION' => 'Bearer twice-over',
            ];

            self::assertSame('Bearer twice-over', Request::capture()->header('Authorization'));
        } finally {
            $_SERVER = $server;
        }
    }

    /**
     * The header as it arrived wins over the copy a rewrite left behind. They
     * hold the same value in practice; the rule is here so that "in practice"
     * is not what it rests on.
     */
    public function testTheUnprefixedSpellingWins(): void
    {
        $server = $_SERVER;

        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/airports',
                'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer stale',
                'HTTP_AUTHORIZATION' => 'Bearer real',
            ];

            self::assertSame('Bearer real', Request::capture()->header('Authorization'));
        } finally {
            $_SERVER = $server;
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function from(array $headers, string $remoteAddress): Request
    {
        return new Request(
            query: new Input(),
            body: new Input(),
            cookies: new Input(),
            headers: $headers,
            remoteAddress: $remoteAddress,
        );
    }

    public function testTheClientIpIsTheRemoteAddressWithNoEdgeInFront(): void
    {
        self::assertSame('203.0.113.9', self::from([], '203.0.113.9')->clientIp());
    }

    /**
     * Behind Cloudflare, REMOTE_ADDR is Cloudflare.
     *
     * Every request arrives from one of its addresses, so anything keyed on
     * REMOTE_ADDR counts the whole internet as one caller -- which for a rate
     * limit means the first script to find the form locks out everybody else.
     */
    public function testTheEdgeReportsWhoTheVisitorActuallyIs(): void
    {
        self::assertSame(
            '203.0.113.9',
            self::from(['cf-connecting-ip' => '203.0.113.9'], '172.68.0.1')->clientIp(),
        );
    }

    /**
     * The header is only something the client typed, on a request that reached
     * the origin directly. It is used when it parses as an address and ignored
     * when it does not, so nothing forged reaches a database key.
     */
    public function testAnUnparseableForwardedAddressIsIgnored(): void
    {
        foreach (["nonsense", "1; DROP TABLE rate_limits", "", "999.1.1.1"] as $forged) {
            self::assertSame(
                '172.68.0.1',
                self::from(['cf-connecting-ip' => $forged], '172.68.0.1')->clientIp(),
                sprintf('%s was treated as an address', var_export($forged, true)),
            );
        }
    }

    public function testAnIpv6VisitorSurvivesTheRoundTrip(): void
    {
        self::assertSame(
            '2001:db8::8a2e:370:7334',
            self::from(['cf-connecting-ip' => '2001:db8::8a2e:370:7334'], '172.68.0.1')->clientIp(),
        );
    }
}
