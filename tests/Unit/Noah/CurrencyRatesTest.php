<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TripBuilder\Noah\Currency\Rates;

/**
 * Reading a rates payload, and refusing a bad one.
 *
 * `parse()` is static and takes a string precisely so this file exists: the
 * command's judgement is all here, and none of it needs the network. CI has the
 * curl extension but no promised route out, so a test that fetched would be
 * flaky rather than thorough.
 *
 * Every refusal below is a way a bad response becomes a wrong price rather than
 * an error. The truncation case is the one that matters most, because a short
 * body is still valid JSON.
 */
final class CurrencyRatesTest extends TestCase
{
    /** The codes the catalogue offers, trimmed to what these cases need. */
    private const array WANTED = ['CAD', 'USD', 'EUR', 'JPY'];

    /**
     * A real response, cut down to the codes above.
     *
     * Kept verbatim in shape -- `amount`, `base`, `date`, `rates` -- because
     * the point of a fixture is to be what the service actually sends. Note
     * what is *not* in it: CAD. The endpoint is asked for rates relative to
     * CAD, so the base is the one code absent from its own answer.
     */
    private const string PAYLOAD = '{"amount":1.0,"base":"CAD","date":"2026-09-09",'
        . '"rates":{"USD":0.7263,"EUR":0.62332,"JPY":111.32}}';

    public function testAGoodPayloadIsRead(): void
    {
        $parsed = Rates::parse(self::PAYLOAD, self::WANTED);

        self::assertSame('2026-09-09', $parsed['date']);
        self::assertSame(['CAD' => 1.0, 'USD' => 0.7263, 'EUR' => 0.62332, 'JPY' => 111.32], $parsed['rates']);
    }

    /**
     * The base currency is supplied, not read.
     *
     * It is absent from every response by construction, and a switcher missing
     * the currency the site is priced in would be a strange thing to ship.
     */
    public function testTheBaseCurrencyIsAddedAtParity(): void
    {
        $rates = Rates::parse(self::PAYLOAD, self::WANTED)['rates'];

        self::assertArrayHasKey('CAD', $rates);
        self::assertSame(1.0, $rates['CAD']);
        self::assertStringNotContainsString('CAD"', substr(self::PAYLOAD, strpos(self::PAYLOAD, '"rates"') ?: 0));
    }

    /**
     * The date is the ECB's, and it has to look like one.
     *
     * It becomes half the primary key, so a missing or malformed date would
     * either fail the insert or file today's rates under something wrong.
     */
    public function testTheDateIsTakenFromThePayload(): void
    {
        self::assertSame('2026-09-09', Rates::parse(self::PAYLOAD, self::WANTED)['date']);
        self::assertNotSame(date('Y-m-d'), '1999-01-01', 'sanity: the date is not simply today');
    }

    /**
     * A truncated body is still valid JSON, and this is the case that catches it.
     *
     * Storing three of four rates while stamping a fresh `fetched_at` would say
     * every currency had just been confirmed when one was left on last week's
     * figure. So nothing is stored, and the missing code is named.
     */
    public function testAnIncompletePayloadIsRefusedAndNamesWhatIsMissing(): void
    {
        $short = '{"amount":1.0,"base":"CAD","date":"2026-09-09","rates":{"USD":0.7263,"EUR":0.62332}}';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/JPY/');

        Rates::parse($short, self::WANTED);
    }

    /**
     * Answered in the wrong base.
     *
     * The worst of these, because it raises nothing anywhere else: rates
     * against the euro applied as though they were against the dollar would
     * reprice the entire site and every page would still render.
     */
    public function testAPayloadQuotedAgainstAnotherCurrencyIsRefused(): void
    {
        $eur = '{"amount":1.0,"base":"EUR","date":"2026-09-09","rates":{"USD":1.16,"CAD":1.6,"JPY":178.6}}';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not CAD/');

        Rates::parse($eur, self::WANTED);
    }

    /**
     * A rate of zero would make every price in that currency free.
     */
    public function testAZeroOrNegativeRateIsRefused(): void
    {
        $zero = '{"amount":1.0,"base":"CAD","date":"2026-09-09","rates":{"USD":0.7263,"EUR":0,"JPY":111.32}}';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/EUR/');

        Rates::parse($zero, self::WANTED);
    }

    #[DataProvider('unusableBodies')]
    public function testAnUnusableBodyIsRefused(string $body): void
    {
        $this->expectException(RuntimeException::class);

        Rates::parse($body, self::WANTED);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['<html>502 Bad Gateway</html>'];
        yield 'json but not an object' => ['"ok"'];
        yield 'no rates key' => ['{"amount":1.0,"base":"CAD","date":"2026-09-09"}'];
        yield 'empty rates' => ['{"amount":1.0,"base":"CAD","date":"2026-09-09","rates":{}}'];
        yield 'no date' => ['{"amount":1.0,"base":"CAD","rates":{"USD":0.7263,"EUR":0.6,"JPY":111.0}}'];
        yield 'unusable date' => ['{"amount":1.0,"base":"CAD","date":"yesterday","rates":{"USD":0.7,"EUR":0.6,"JPY":111.0}}'];
        yield 'a rate sent as a string' => ['{"amount":1.0,"base":"CAD","date":"2026-09-09","rates":{"USD":"0.7263","EUR":0.6,"JPY":111.0}}'];
    }
}
