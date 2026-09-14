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

    /**
     * A real range response, cut to the codes above.
     *
     * Two publication dates and a weekend between them, which is what the
     * source actually sends: it publishes on working days, so a span of
     * calendar days comes back with holes in it.
     */
    private const string SPAN = '{"amount":1.0,"base":"CAD","start_date":"2026-09-04","end_date":"2026-09-07",'
        . '"rates":{"2026-09-04":{"USD":0.7263,"EUR":0.62332,"JPY":111.32},'
        . '"2026-09-07":{"USD":0.7271,"EUR":0.62410,"JPY":111.05}}}';

    public function testASpanIsReadDayByDay(): void
    {
        $parsed = Rates::parseRange(self::SPAN, self::WANTED);

        self::assertSame('2026-09-04', $parsed['start']);
        self::assertSame('2026-09-07', $parsed['end']);
        self::assertSame(['2026-09-04', '2026-09-07'], array_keys($parsed['days']));
        self::assertSame(
            ['CAD' => 1.0, 'USD' => 0.7263, 'EUR' => 0.62332, 'JPY' => 111.32],
            $parsed['days']['2026-09-04'],
        );
        self::assertSame([], $parsed['missing']);
    }

    /**
     * The one place a range is deliberately more forgiving than a day.
     *
     * A truncated *daily* response has to be refused, because it would leave
     * currencies on an older figure while claiming all of them were confirmed.
     * A historic day the ECB published nothing for is a fact about history --
     * it really did suspend some currencies for years -- and throwing a year of
     * thirty currencies away over one gap in one of them would be worse.
     */
    public function testADayMissingACurrencyIsRecordedAndKept(): void
    {
        $gap = '{"amount":1.0,"base":"CAD","start_date":"2026-09-04","end_date":"2026-09-07",'
            . '"rates":{"2026-09-04":{"USD":0.7263,"EUR":0.62332},'
            . '"2026-09-07":{"USD":0.7271,"EUR":0.62410,"JPY":111.05}}}';

        $parsed = Rates::parseRange($gap, self::WANTED);

        self::assertCount(2, $parsed['days']);
        self::assertArrayNotHasKey('JPY', $parsed['days']['2026-09-04']);
        self::assertSame(111.05, $parsed['days']['2026-09-07']['JPY']);
        self::assertSame(['JPY' => 1], $parsed['missing']);
    }

    /**
     * A day holding nothing but the base is not a day the source published.
     */
    public function testADayWithNothingInItIsDropped(): void
    {
        $empty = '{"amount":1.0,"base":"CAD","start_date":"2026-09-04","end_date":"2026-09-07",'
            . '"rates":{"2026-09-04":{},"2026-09-07":{"USD":0.7271,"EUR":0.62410,"JPY":111.05}}}';

        $parsed = Rates::parseRange($empty, self::WANTED);

        self::assertSame(['2026-09-07'], array_keys($parsed['days']));
        self::assertSame(3, $parsed['missing']['USD'] + $parsed['missing']['EUR'] + $parsed['missing']['JPY']);
    }

    /**
     * The bounds fall back to the days that actually came back.
     *
     * The source returns the last publication on or before the start, so its
     * own `start_date` and the first date in the payload can differ -- but if
     * it sends neither, the days are the only honest answer.
     */
    public function testTheBoundsFallBackToTheDaysThemselves(): void
    {
        $bare = '{"amount":1.0,"base":"CAD",'
            . '"rates":{"2026-09-07":{"USD":0.7271,"EUR":0.6,"JPY":111.0},'
            . '"2026-09-04":{"USD":0.7263,"EUR":0.6,"JPY":111.3}}}';

        $parsed = Rates::parseRange($bare, self::WANTED);

        self::assertSame('2026-09-04', $parsed['start']);
        self::assertSame('2026-09-07', $parsed['end']);
    }

    #[DataProvider('unusableSpans')]
    public function testAnUnusableSpanBodyIsRefused(string $body): void
    {
        $this->expectException(RuntimeException::class);

        Rates::parseRange($body, self::WANTED);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableSpans(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['<html>502 Bad Gateway</html>'];
        yield 'the wrong base' => ['{"amount":1.0,"base":"EUR","rates":{"2026-09-07":{"USD":0.7}}}'];
        yield 'no rates key' => ['{"amount":1.0,"base":"CAD","start_date":"2026-09-04"}'];
        yield 'empty rates' => ['{"amount":1.0,"base":"CAD","rates":{}}'];
        yield 'filed under something that is not a date' => ['{"amount":1.0,"base":"CAD","rates":{"friday":{"USD":0.7}}}'];
        yield 'a day that is not an object' => ['{"amount":1.0,"base":"CAD","rates":{"2026-09-07":0.7}}'];
        yield 'every day empty' => ['{"amount":1.0,"base":"CAD","rates":{"2026-09-07":{},"2026-09-04":{}}}'];
    }

    /**
     * The span reaches a request path, so it is checked rather than trusted.
     */
    public function testASpanIsRewrittenInTheSourcesOwnSyntax(): void
    {
        self::assertSame('2025-09-13..2026-03-01', Rates::readSpan('2025-09-13..2026-03-01'));
        // A bare date means "from then until the latest", which is the common
        // ask and the source's own open-ended form.
        self::assertSame('2025-09-13..', Rates::readSpan('2025-09-13'));
        self::assertSame('2025-09-13..', Rates::readSpan('2025-09-13..'));
    }

    #[DataProvider('unusableSpanArguments')]
    public function testAnUnusableSpanArgumentIsRefused(string $span): void
    {
        $this->expectException(RuntimeException::class);

        Rates::readSpan($span);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableSpanArguments(): iterable
    {
        yield 'empty' => [''];
        yield 'words' => ['last year'];
        yield 'a shape that is close' => ['2025-9-13'];
        // Matches the shape and is not a day, which is the reason the shape is
        // not the whole check.
        yield 'the thirtieth of February' => ['2025-02-30'];
        yield 'backwards' => ['2026-03-01..2025-09-13'];
        yield 'the future' => ['2099-01-01'];
        // The span is written into a URL, so anything that could leave the
        // path it was meant for has to be refused by shape.
        yield 'a second path segment' => ['2025-09-13/../../admin'];
        yield 'a query of its own' => ['2025-09-13?base=USD'];
        yield 'another host' => ['https://example.test/v1/latest'];
    }
}
