<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Helper;
use TripBuilder\Money;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * What the money macros actually emit.
 *
 * This tests the substitution rather than each of its twenty-eight
 * applications, and that is the point. Every site that used to print a price
 * spelled it identically -- a literal `$`, the whole amount, a hard `.`, the
 * cents -- so if the macro reproduces that string exactly, every site using it
 * is correct by construction. Rendering all seventeen templates instead would
 * need a fixture apiece and would still only prove the same one thing.
 *
 * The CAD expectations below are the exact markup the templates carried before
 * the macros existed. They are copied from the diff, not derived, so they can
 * disagree with the formatter and fail.
 */
final class MoneyMacroRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    /**
     * The rich form, byte for byte as `search/cards/body.html.twig` wrote it.
     */
    public function testRichReproducesTheMarkupItReplaced(): void
    {
        self::assertSame(
            '<span class="price__symbol">$</span><span class="price__whole">1,234</span>'
            . '<span class="price__cents">.56</span>',
            $this->macro('rich', Money::base()->parts(1234.56)),
        );
    }

    /**
     * A currency with no minor unit gets no cents span at all.
     *
     * Not an empty one and not "00": the element carries a separator, so
     * printing it for a yen price would put a decimal point in a figure that
     * has no decimals.
     */
    public function testRichOmitsTheCentsSpanWhereThereAreNoCents(): void
    {
        $rendered = $this->macro('rich', $this->money('JPY', 111.32)->parts(100.0));

        self::assertStringNotContainsString('price__cents', $rendered);
        self::assertSame(
            '<span class="price__symbol">¥</span><span class="price__whole">11,132</span>',
            $rendered,
        );
    }

    /**
     * And the symbol moves rather than the value.
     *
     * Sweden writes 1 234,56 kr. The old markup had the symbol span first
     * unconditionally, so this is the one case the macro has to reorder.
     */
    public function testRichPutsTheSymbolLastWhereTheCurrencyDoes(): void
    {
        $rendered = $this->macro('rich', $this->money('SEK', 1.0)->parts(1234.56));

        self::assertStringStartsWith('<span class="price__whole">', $rendered);
        self::assertStringEndsWith('<span class="price__symbol price__symbol--after">kr</span>', $rendered);
        self::assertStringContainsString("<span class=\"price__whole\">1\u{00A0}234</span>", $rendered);
        self::assertStringContainsString('<span class="price__cents">,56</span>', $rendered);
    }

    /**
     * The plain form, as the `<dd>` rows and the Pay button wrote it.
     */
    public function testPlainReproducesTheStringItReplaced(): void
    {
        self::assertSame('$1,234.56', $this->macro('plain', Money::base()->parts(1234.56)));
        self::assertSame('¥11,132', $this->macro('plain', $this->money('JPY', 111.32)->parts(100.0)));
    }

    /**
     * The whole form, as the filter labels and sort tabs wrote it.
     */
    public function testWholeReproducesTheStringItReplaced(): void
    {
        self::assertSame('$1,234', $this->macro('whole', Money::base()->parts(1234.56)));
    }

    /**
     * A fare quoted "from" is floored, and a difference is rounded.
     *
     * The two live a line apart in Money and want opposite things from the same
     * fraction: a fare advertised at 464 has to exist at 464, while a gap reads
     * better at the nearest whole unit.
     */
    public function testAFareFloorsAndADifferenceRounds(): void
    {
        $money = Money::base();

        self::assertSame('$464', $money->whole(464.99)['text'], 'a quoted fare must not round up');
        self::assertSame('$465', $money->rounded(464.99)['text'], 'a difference takes the nearest');
    }

    /**
     * And nothing spells a price for itself any more.
     *
     * The macros are only worth having if everything goes through them, and a
     * new template printing `${{ x.whole }}` would look completely normal in
     * review -- it is what every one of these files said until this commit.
     *
     * Comments are stripped first, for the reason PromisesTest strips them: the
     * explanation of what was removed has to be allowed to quote it.
     *
     * The meta description is the one exemption and it is deliberate.
     * `route/view.html.twig` writes its price into <head>, where markup cannot
     * go and where the reader is a crawler that carries no cookie -- so it
     * stays Canadian dollars rather than becoming one URL serving a different
     * description per visitor.
     */
    public function testNoTemplateSpellsAPriceForItself(): void
    {
        $exempt = ['route/view.html.twig'];
        $offences = [];

        foreach ($this->templates() as $path => $markup) {
            if (in_array($path, $exempt, true)) {
                continue;
            }

            $prose = (string) preg_replace('/\{#.*?#\}/s', '', $markup);

            foreach (['${{', '>CAD<', "'\$' ~", '$" ~'] as $literal) {
                if (str_contains($prose, $literal)) {
                    $offences[] = $path . ' writes ' . $literal;
                }
            }
        }

        self::assertSame([], $offences);
    }

    /**
     * Every template, keyed by its path below frontend/template.
     *
     * @return array<string, string>
     */
    private function templates(): array
    {
        $root = Helper::getRootDir() . '/frontend/template';
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $found[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertNotEmpty($found, 'no templates found to check');
        ksort($found);

        return $found;
    }

    private function money(string $code, float $rate): Money
    {
        $currency = Currency::tryFrom($code);

        self::assertNotNull($currency);

        return new Money($currency, $rate);
    }

    /**
     * One macro, rendered on its own.
     *
     * A string template rather than a fixture-heavy page, because the macro is
     * what is under test and every caller passes it the same shape.
     *
     * @param array<string, mixed> $price
     */
    private function macro(string $name, array $price): string
    {
        $twig = new Environment(new FilesystemLoader(Helper::getRootDir() . '/frontend/template'), [
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);

        return $twig
            ->createTemplate("{% import 'partials/money.html.twig' as money %}{{ money.$name(price) }}")
            ->render(['price' => $price]);
    }
}
