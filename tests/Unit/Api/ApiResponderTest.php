<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Throwable;
use TripBuilder\Api\ApiRefusal;
use TripBuilder\Api\ApiResponder;
use TripBuilder\Api\HttpMethod;
use TripBuilder\Http\HttpStatus;

/**
 * How an endpoint says no.
 *
 * Every one of these used to end in `die()`, which is why there was no test:
 * the first assertion would have ended the run (E10.5, #198).
 */
final class ApiResponderTest extends TestCase
{
    public function testEachRefusalCarriesItsOwnStatus(): void
    {
        $refusals = [
            HttpStatus::BadRequest->value => static fn() => ApiResponder::badRequest(),
            HttpStatus::Unauthorized->value => static fn() => ApiResponder::unauthorizedAccess(),
            HttpStatus::NotFound->value => static fn() => ApiResponder::notFound(),
        ];

        foreach ($refusals as $status => $refuse) {
            self::assertSame($status, self::refusalFrom($refuse)->status->value);
        }
    }

    /**
     * Without a message the status names itself, which is what the `die()`
     * version wrote and what a client reads when the endpoint has nothing to
     * add.
     */
    public function testTheStatusNamesItselfWhenNothingElseDoes(): void
    {
        self::assertSame('Not Found', self::refusalFrom(static fn() => ApiResponder::notFound())->getMessage());
        self::assertSame(
            'Flight not found',
            self::refusalFrom(static fn() => ApiResponder::notFound('Flight not found'))->getMessage(),
        );
    }

    /**
     * The one refusal with something to add. A 405 that does not say what is
     * allowed leaves a client guessing at the verb.
     */
    public function testTheMethodRefusalSaysWhatIsAllowed(): void
    {
        $refusal = self::refusalFrom(
            static fn() => ApiResponder::methodNotAllowed([HttpMethod::Post, HttpMethod::Get]),
        );

        self::assertSame(HttpStatus::MethodNotAllowed->value, $refusal->status->value);
        self::assertSame(['Access-Control-Allow-Methods' => 'POST,GET'], $refusal->headers);
    }

    /** And the others add none, because `Allow` means nothing on a 401. */
    public function testTheOtherRefusalsAddNoHeaders(): void
    {
        self::assertSame([], self::refusalFrom(static fn() => ApiResponder::unauthorizedAccess())->headers);
    }

    /**
     * The envelope, which is the refusal's own and deliberately not the one
     * `AbstractApi::sendResponse()` wraps a success in.
     */
    public function testARefusalIsWrittenAsItsStatusAndItsReason(): void
    {
        $written = self::capture(new ApiRefusal(HttpStatus::BadRequest, 'Malformed JSON body'));

        self::assertSame(
            ['status' => 400, 'data' => 'Malformed JSON body'],
            json_decode($written, true),
        );
    }

    /**
     * The refusal a call throws.
     *
     * Through a callable rather than inline, because the methods are typed
     * `never` and an analyser reading that calls anything after the call
     * unreachable — including the assertion that says it should have thrown.
     */
    private static function refusalFrom(callable $refuse): ApiRefusal
    {
        try {
            $refuse();
        } catch (ApiRefusal $refusal) {
            return $refusal;
        }

        self::fail('the call should have refused');
    }

    /** Run send() and hand back what it printed. */
    private static function capture(ApiRefusal $refusal): string
    {
        ob_start();

        try {
            ApiResponder::send($refusal);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }
}
