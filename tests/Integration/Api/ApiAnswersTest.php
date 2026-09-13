<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Api;

use Throwable;
use TripBuilder\Api\HttpMethod;
use TripBuilder\Config;
use TripBuilder\Env;
use TripBuilder\EnvKey;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\Input;
use TripBuilder\Http\Kernel;
use TripBuilder\Http\Request;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\Timer;

/**
 * The API answers, rather than ending the process.
 *
 * None of this could be written before E10.5 (#198). `ApiResponder` ended every
 * refusal in `die()`, so the first case here would have ended the run — and
 * `AbstractApi::getAuthToken()` called `getallheaders()`, which does not exist
 * on CLI, so the run would have ended on an undefined function before even
 * reaching a guard.
 *
 * Driven through `Kernel`, which is what the server runs, so the envelope and
 * the status are the ones a client would receive.
 */
final class ApiAnswersTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        new Config('common');
        Timer::start();
    }

    public function testNoTokenIsRefusedAsJson(): void
    {
        $answer = $this->ask(HttpMethod::Get, '/api/airports');

        self::assertSame(HttpStatus::Unauthorized->value, $answer['status']);
        self::assertSame(['status' => 401, 'data' => 'Unauthorized'], json_decode($answer['body'], true));
    }

    /**
     * The guard order matters: a wrong verb with no token is answered as the
     * missing token, because an endpoint should not tell an unauthenticated
     * caller which verbs it takes.
     */
    public function testTheWrongVerbIsRefusedOnlyOnceTheCallerIsKnown(): void
    {
        $answer = $this->ask(HttpMethod::Get, '/api/airports', $this->token());

        self::assertSame(HttpStatus::MethodNotAllowed->value, $answer['status']);
        self::assertSame(['status' => 405, 'data' => 'Method Not Allowed'], json_decode($answer['body'], true));
    }

    /** A body that is not JSON is the endpoint's own refusal, not a guard's. */
    public function testAMalformedBodyIsRefusedAsABadRequest(): void
    {
        $answer = $this->ask(HttpMethod::Post, '/api/airports', $this->token(), rawBody: '{not json');

        self::assertSame(HttpStatus::BadRequest->value, $answer['status']);
        self::assertSame(['status' => 400, 'data' => 'Malformed JSON body'], json_decode($answer['body'], true));
    }

    /**
     * The control, and the half that matters most.
     *
     * The token now reaches the guard through the request object rather than
     * `getallheaders()`, so this is what says the swap did not quietly turn
     * every authorised call into a 401.
     */
    public function testAnAuthorisedCallIsAnswered(): void
    {
        $answer = $this->ask(HttpMethod::Post, '/api/airports', $this->token());

        self::assertSame(HttpStatus::Ok->value, $answer['status']);

        $payload = json_decode($answer['body'], true);

        self::assertIsArray($payload);
        self::assertSame(200, $payload['status'] ?? null);
        // The success envelope, which is a different shape from a refusal's.
        self::assertSame('/api/airports', $payload['endpoint'] ?? null);
        self::assertArrayHasKey('data', $payload);
    }

    /** The first accepted token, or a skip when this machine configures none. */
    private function token(): string
    {
        $tokens = array_values(array_filter(explode(',', Env::get(EnvKey::ApiAcceptedTokens))));

        if ($tokens === []) {
            self::markTestSkipped('API_ACCEPTED_TOKENS is empty, so no call can be authorised.');
        }

        return $tokens[0];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function ask(HttpMethod $method, string $path, ?string $token = null, string $rawBody = ''): array
    {
        $this->connection();

        http_response_code(HttpStatus::Ok->value);

        try {
            $body = new Kernel(new Request(
                new Input(),
                new Input(),
                new Input(),
                method: $method->value,
                uri: $path,
                rawBody: $rawBody,
                headers: $token === null ? [] : ['authorization' => 'Bearer ' . $token],
            ))->handle();

            return ['status' => (int) http_response_code(), 'body' => $body];
        } catch (Throwable $e) {
            self::fail(sprintf('%s %s threw %s: %s', $method->value, $path, $e::class, $e->getMessage()));
        } finally {
            http_response_code(HttpStatus::Ok->value);
        }
    }
}
