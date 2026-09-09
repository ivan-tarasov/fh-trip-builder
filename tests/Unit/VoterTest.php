<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use TripBuilder\Voter;

/**
 * The token that stands in for an account when somebody rates an article.
 *
 * Two things here fail quietly. A token that is accepted without being checked
 * reaches a `char(32)` column, where a longer value is stored truncated -- so
 * two readers who edited their own cookies could land on one row and overwrite
 * each other's vote. And a token that is *re*-minted when a good one already
 * exists orphans the vote already recorded against it, which reads to the
 * visitor as their vote being forgotten and to the table as a second voter.
 *
 * The minting tests run in their own process because `identify()` calls
 * `setcookie()`, which warns once output has been sent -- and `failOnWarning`
 * in phpunit.xml.dist turns that warning into a failure. This is the only place
 * in the suite that needs it; RecentSearchesTest sidesteps the same problem by
 * never testing its `remember()`, and that left the one branch that matters
 * here uncovered.
 */
final class VoterTest extends TestCase
{
    /** Shaped like a real one: 32 lowercase hex characters. */
    private const string VALID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        unset($_COOKIE[Voter::COOKIE]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[Voter::COOKIE]);
    }

    public function testNoCookieIsNoVoter(): void
    {
        self::assertNull(Voter::current());
    }

    public function testAWellFormedTokenIsAccepted(): void
    {
        $_COOKIE[Voter::COOKIE] = self::VALID;

        self::assertSame(self::VALID, Voter::current());
    }

    /**
     * Reading never hands out an identity.
     *
     * The rule the cookie notice's wording depends on: somebody who only reads
     * an article is not tagged. If this ever started minting, every visitor to
     * every help page would leave with a cookie they were never told about.
     */
    public function testReadingDoesNotMintAToken(): void
    {
        self::assertNull(Voter::current());
        self::assertArrayNotHasKey(Voter::COOKIE, $_COOKIE, 'current() must not write');
    }

    /**
     * Anything not exactly 32 lowercase hex characters is no token at all.
     *
     * Rejected rather than repaired, on Currency::tryFrom()'s reasoning: a
     * cookie is whatever the browser sent, so a value we did not generate is
     * somebody editing it by hand. The over-long case is the one with teeth --
     * MySQL would store the first 32 characters, so two edited cookies sharing
     * a prefix would become one voter.
     *
     * The array is on the list because `tb_voter[]=x` in a URL arrives as one.
     */
    #[DataProvider('rubbish')]
    public function testAnythingElseIsNoVoter(mixed $value): void
    {
        $_COOKIE[Voter::COOKIE] = $value;

        self::assertNull(Voter::current());
    }

    /** @return iterable<string, array{mixed}> */
    public static function rubbish(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['a1b2c3'];
        yield 'one character short' => [substr(self::VALID, 1)];
        yield 'one character long' => [self::VALID . 'a'];
        yield 'much too long, sharing a prefix' => [self::VALID . self::VALID];
        yield 'uppercase' => [strtoupper(self::VALID)];
        yield 'right length, not hex' => [str_repeat('z', 32)];
        yield 'padded' => [' ' . substr(self::VALID, 1)];
        yield 'trailing newline' => [self::VALID . "\n"];
        yield 'a word' => ['anonymous'];
        yield 'an array' => [[self::VALID]];
        yield 'an integer' => [12345];
    }

    /**
     * An existing token is returned untouched.
     *
     * The branch that must never re-mint: a reader who has already voted has a
     * row keyed on this token, and handing them a new one would leave that vote
     * belonging to nobody and let them cast another.
     *
     * No separate process needed -- this path returns before `setcookie()`.
     */
    public function testIdentifyKeepsATokenItAlreadyHas(): void
    {
        $_COOKIE[Voter::COOKIE] = self::VALID;

        self::assertSame(self::VALID, Voter::identify(false));
        self::assertSame(self::VALID, Voter::identify(true), 'and the scheme does not change it');
    }

    #[RunInSeparateProcess]
    public function testIdentifyMintsATokenForSomebodyWhoHasNone(): void
    {
        unset($_COOKIE[Voter::COOKIE]);

        $token = Voter::identify(false);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        self::assertSame(32, strlen($token), 'the voter column is char(32)');
    }

    /**
     * And it is readable straight away, in the same request.
     *
     * `setcookie()` only sets a response header, so `$_COOKIE` would not carry
     * the new token until the next request -- but the vote being recorded now
     * needs it. Asserted because the write-back that makes this true is one
     * line and looks removable.
     */
    #[RunInSeparateProcess]
    public function testAFreshTokenIsUsableWithinTheSameRequest(): void
    {
        unset($_COOKIE[Voter::COOKIE]);

        $token = Voter::identify(false);

        self::assertSame($token, Voter::current());
        self::assertSame($token, Voter::identify(false), 'a second call is the same voter, not a new one');
    }

    /**
     * A hand-edited cookie is replaced, not honoured and not fatal.
     *
     * `current()` rejects it, so this reader has no usable identity -- but they
     * are still entitled to vote, so `identify()` has to mint rather than
     * return the rubbish or refuse.
     */
    #[RunInSeparateProcess]
    public function testAMalformedTokenIsReplacedRatherThanKept(): void
    {
        $_COOKIE[Voter::COOKIE] = 'not-a-token';

        $token = Voter::identify(false);

        self::assertNotSame('not-a-token', $token);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    /**
     * Two browsers do not share a voter.
     *
     * Sixteen random bytes, so a collision is not a practical concern -- but
     * a constant, or a token derived from something about the visitor, would
     * pass every other test in this file while merging readers into one vote.
     */
    #[RunInSeparateProcess]
    public function testTokensAreNotPredictable(): void
    {
        $tokens = [];

        for ($i = 0; $i < 50; $i++) {
            unset($_COOKIE[Voter::COOKIE]);
            $tokens[] = Voter::identify(false);
        }

        self::assertCount(50, array_unique($tokens), 'every voter should be a different voter');
    }
}
