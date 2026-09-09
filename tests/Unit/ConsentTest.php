<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Consent;

/**
 * The gate in front of two analytics vendors, one of which records the session.
 *
 * Every case here is really the same question asked from a different angle: is
 * this value a yes? Only one string is, and everything else -- no cookie, an
 * empty cookie, a stale value from an older scheme, something a visitor typed
 * into their own browser -- has to fall the same way, because the cost of
 * getting it wrong is running a session recorder on somebody who never agreed.
 */
final class ConsentTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_COOKIE[Consent::COOKIE]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[Consent::COOKIE]);
    }

    public function testNoCookieIsNotConsentAndNotAnAnswer(): void
    {
        self::assertFalse(Consent::granted());
        self::assertFalse(Consent::answered(), 'an unanswered visitor should still be asked');
    }

    public function testGrantedIsTheOnlyYes(): void
    {
        $_COOKIE[Consent::COOKIE] = Consent::GRANTED;

        self::assertTrue(Consent::granted());
        self::assertTrue(Consent::answered());
    }

    public function testDeniedIsAnAnswerButNotConsent(): void
    {
        $_COOKIE[Consent::COOKIE] = Consent::DENIED;

        self::assertFalse(Consent::granted(), 'declining must not load anything');
        self::assertTrue(Consent::answered(), 'and must not be asked again');
    }

    /**
     * Anything else is treated as no answer at all.
     *
     * Fail closed and fail asking: a value this code does not recognise cannot
     * be read as permission, and pretending it was an answer would leave the
     * visitor with no way back to the question.
     *
     * The array is on the list because `$_COOKIE` is whatever the browser sent.
     * `tb_cookie_consent[]=granted` in a URL makes that value an array, and a
     * loose comparison against a string would have said yes to it.
     */
    #[DataProvider('rubbish')]
    public function testAnythingElseIsNeitherConsentNorAnAnswer(mixed $value): void
    {
        $_COOKIE[Consent::COOKIE] = $value;

        self::assertFalse(Consent::granted());
        self::assertFalse(Consent::answered());
    }

    /** @return iterable<string, array{mixed}> */
    public static function rubbish(): iterable
    {
        yield 'empty' => [''];
        yield 'yes' => ['yes'];
        yield 'true' => ['true'];
        yield 'one' => ['1'];
        yield 'wrong case' => ['GRANTED'];
        yield 'padded' => [' granted'];
        yield 'an array' => [['granted']];
    }
}
