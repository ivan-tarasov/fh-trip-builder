<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Money;

/**
 * Turning a Canadian dollar amount into a number somebody reads.
 *
 * Three things here can be wrong without anything failing. A rate applied the
 * wrong way round raises no error and prints prices 38% out. A zero-decimal
 * currency printed with cents looks merely odd rather than broken. And base,
 * tax and total rounded separately disagree by a single unit, on a page headed
 * "Total paid", which is where somebody does check the arithmetic.
 */
final class MoneyTest extends TestCase
{
    /**
     * The figures PartyPriceAgreementTest uses, and for its reason: scaled by a
     * child's 0.75 they land on a half cent, which is where rounding decisions
     * stop being invisible.
     */
    private const float AWKWARD_BASE = 111.11;
    private const float AWKWARD_TAX = 17.77;

    /**
     * Rates supplied by the test, not read from config.
     *
     * The formatter's arithmetic has nothing to do with today's market, and a
     * test that read live rates would pass or fail on the news. A rate below 1,
     * one in the hundreds, one in the thousands, and parity, so every
     * combination of minor unit and separator meets each magnitude.
     *
     * @var list<float>
     */
    private const array RATES = [0.7263, 3.14159, 111.32, 12693.0, 1.0];

    protected function setUp(): void
    {
        new Config('common');
    }

    /**
     * The rate multiplies. It does not divide.
     *
     * One Canadian dollar buys 0.7263 US dollars, so a hundred of them is
     * US$72.63 and never US$137.68. This is the whole of the inversion bug and
     * it is worth a test of its own, because every other assertion in this file
     * would pass with the rate upside down.
     */
    public function testTheRateConvertsAwayFromCanadianDollars(): void
    {
        $money = new Money(self::currency('USD'), 0.7263);

        self::assertSame('72', $money->parts(100.0)['whole']);
        self::assertSame('63', $money->parts(100.0)['cents']);
        self::assertNotSame('137', $money->parts(100.0)['whole'], 'the rate has been inverted');
    }

    public function testTheBaseCurrencyPassesThroughUnchanged(): void
    {
        $parts = Money::base()->parts(1234.56);

        self::assertSame('$', $parts['symbol']);
        self::assertSame('1,234', $parts['whole']);
        self::assertSame('56', $parts['cents']);
        self::assertSame('CAD', $parts['code']);
        self::assertSame('$1,234.56', $parts['text']);
    }

    /**
     * A currency with no minor unit reports no cents at all.
     *
     * Null and not "00", so a template can leave the separator out instead of
     * printing ¥1,234.00 — there is no such coin. The old splitter exploded on
     * '.' and would have raised "Undefined array key 1" here, which
     * phpunit.xml.dist turns into a failure.
     */
    #[DataProvider('zeroDecimalCurrencies')]
    public function testAZeroDecimalCurrencyHasNoCents(string $code): void
    {
        $currency = self::currency($code);
        $parts = (new Money($currency, 100.0))->parts(12.34);

        self::assertNull($parts['cents'], $code . ' should have no minor unit');

        // The currency's own decimal mark, not a full stop: Iceland groups
        // thousands with a stop and marks decimals with a comma, so "1.234 kr"
        // is a whole number and asserting on '.' would fail a correct price.
        self::assertStringNotContainsString(
            $currency->point,
            $parts['text'],
            $code . ' should print no decimal mark',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function zeroDecimalCurrencies(): iterable
    {
        yield 'yen' => ['JPY'];
        yield 'won' => ['KRW'];
        yield 'krona' => ['ISK'];
    }

    /**
     * Where the symbol goes, and what separates the digits.
     *
     * Sweden writes 1 234,56 kr. Printing $1,234.56 with the dollar swapped for
     * a kr would be Canadian digits in a Swedish hat, and the reader who
     * switched to SEK is exactly the one who would notice.
     */
    public function testTheSymbolAndSeparatorsAreTheCurrencysOwn(): void
    {
        $sek = (new Money(self::currency('SEK'), 1.0))->parts(1234.56);

        self::assertSame("1\u{00A0}234", $sek['whole'], 'Swedish groups with a space');
        self::assertSame('56', $sek['cents']);
        self::assertSame("1\u{00A0}234,56\u{00A0}kr", $sek['text'], 'and puts the symbol last');

        $idr = (new Money(self::currency('IDR'), 1.0))->parts(1234.56);

        self::assertSame('1.234', $idr['whole'], 'Indonesian groups with a stop');
        self::assertSame('Rp1.234,56', $idr['text']);
    }

    /**
     * The group separator is non-breaking, so a price cannot wrap mid-number.
     *
     * Same reasoning as the route labels: "1" at the end of one line and "234
     * kr" at the start of the next is not a price.
     */
    public function testASpacedGroupSeparatorIsNonBreaking(): void
    {
        foreach (Currency::all() as $code => $currency) {
            self::assertNotSame(' ', $currency->group, $code . ' groups with a breakable space');
        }
    }

    /**
     * Base plus tax is the total, in every currency in the catalogue.
     *
     * The assertion that matters, and the one the receipt rests on.
     */
    public function testBaseAndTaxAlwaysAddUpToTheTotal(): void
    {
        foreach (Currency::all() as $code => $currency) {
            foreach (self::RATES as $rate) {
                $split = (new Money($currency, $rate))->split(self::AWKWARD_BASE, self::AWKWARD_TAX);

                self::assertSame(
                    self::minorOf($split['total'], $currency),
                    self::minorOf($split['base'], $currency) + self::minorOf($split['tax'], $currency),
                    $code . ' at ' . $rate . ': base and tax do not add up to the total',
                );
            }
        }
    }

    /**
     * And that test is not vacuous.
     *
     * Rounding the three figures independently really does disagree with the
     * total for some of these currencies — if it never did, the test above
     * would pass on any implementation and prove nothing. This asserts that at
     * least one catalogue entry is a case where the naive sum is wrong, so the
     * residual is doing visible work.
     */
    public function testRoundingTheThreeFiguresSeparatelyWouldDisagree(): void
    {
        $disagreements = [];

        foreach (Currency::all() as $code => $currency) {
            foreach (self::RATES as $rate) {
                $scale = 10 ** $currency->decimals;
                $naive = (int) round(self::AWKWARD_BASE * $rate * $scale)
                    + (int) round(self::AWKWARD_TAX * $rate * $scale);
                $total = (int) round((self::AWKWARD_BASE + self::AWKWARD_TAX) * $rate * $scale);

                if ($naive !== $total) {
                    $disagreements[] = $code . '@' . $rate;
                }
            }
        }

        self::assertNotEmpty(
            $disagreements,
            'no currency and rate pair rounds awkwardly, so the adding-up test cannot fail and proves nothing',
        );
    }

    /**
     * Tax carries the rounding, not the total and not the base.
     *
     * The total is what the Pay button says and the base is the fare, so the
     * unit lands on the line nobody adds up by hand.
     */
    public function testTheTotalAndBaseAreConvertedAndTaxIsWhatIsLeft(): void
    {
        $currency = self::currency('JPY');
        $money = new Money($currency, 111.32);
        $split = $money->split(self::AWKWARD_BASE, self::AWKWARD_TAX);

        self::assertSame($money->parts(self::AWKWARD_BASE)['whole'], $split['base']['whole']);
        self::assertSame(
            $money->parts(self::AWKWARD_BASE + self::AWKWARD_TAX)['whole'],
            $split['total']['whole'],
        );
    }

    public function testTextIsUsableWhereMarkupIsNot(): void
    {
        $text = (new Money(self::currency('JPY'), 111.32))->text(100.0);

        self::assertSame('¥11,132', $text);
        self::assertStringNotContainsString('<', $text);
    }

    private static function currency(string $code): Currency
    {
        $currency = Currency::tryFrom($code);

        self::assertNotNull($currency, $code . ' should be in the catalogue');

        return $currency;
    }

    /**
     * The parts read back as an integer count of the smallest unit, so three
     * formatted strings can be added up.
     *
     * @param array{whole: string, cents: ?string} $parts
     */
    private static function minorOf(array $parts, Currency $currency): int
    {
        $whole = (int) preg_replace('/\D/', '', $parts['whole']);

        return $whole * 10 ** $currency->decimals + (int) ($parts['cents'] ?? 0);
    }
}
