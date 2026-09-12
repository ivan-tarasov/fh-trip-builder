<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;
use TripBuilder\Tests\Support\Palette;

/**
 * Every colour pairing the pages rely on, in both palettes.
 *
 * This exists because of how the dark palette was found to be wrong: by the
 * owner looking at screenshots. A pale-blue fare-alert block, a white
 * top-searches list, accent buttons carrying white at 2.20, a header band at
 * 1.01 against the page it sat on. All real, all silent, and every one of them
 * a pair of tokens that could have been measured the moment it was written.
 *
 * The pairs below are the ones the stylesheet actually puts together. They are
 * listed by hand because which ink goes on which surface is a decision, not
 * something a parser can infer -- but the *values* are read from the stylesheet,
 * so a palette change is caught here rather than restated here.
 *
 * A failure is not automatically a demand to change the colour. It is a demand
 * to decide: move the value, or stop using that pairing.
 */
final class PaletteContrastTest extends TestCase
{
    private const float TEXT = 4.5;      /* WCAG AA, body text */
    private const float GRAPHIC = 3.0;   /* WCAG AA, a glyph or a control's edge */

    private static ?Palette $palette = null;

    private static function palette(): Palette
    {
        return self::$palette ??= Palette::fromCss(
            (string) file_get_contents(Helper::getRootDir() . '/frontend/css/main.css'),
        );
    }

    /**
     * @return array<string, array{string, string, float, string}>
     */
    public static function pairs(): array
    {
        $text = [
            'body text on a panel' => ['ink-body', 'surface-raised'],
            'body text on the page' => ['ink-body', 'surface'],
            'secondary text on a panel' => ['ink-secondary', 'surface-raised'],
            'quiet detail on a panel' => ['ink-muted', 'surface-raised'],
            'quiet detail on the page' => ['ink-muted', 'surface'],
            'a link, which is the accent' => ['brand-accent', 'surface-raised'],
            'the ink used as text' => ['brand-ink', 'surface-raised'],
            'success as text' => ['brand-success', 'surface-raised'],
            'danger as text' => ['brand-danger', 'surface-raised'],
            'text on the brand tint' => ['ink-body', 'brand-tint'],
            'text on the success tint' => ['ink-body', 'success-tint'],
            'text on the danger tint' => ['ink-body', 'danger-tint'],
            'on-dark ink on the band' => ['ink-on-dark', 'brand-ink-fill'],
            'the band\'s lead ink' => ['ink-on-dark-lead', 'brand-ink-fill'],
            'a white glyph on the ink band' => ['on-brand', 'brand-ink-fill'],
            'a white glyph on an ink chip' => ['on-brand', 'brand-ink-chip'],
            'a white glyph on an accent fill' => ['on-brand', 'brand-accent-fill'],
            'a white glyph on its hover' => ['on-brand', 'brand-accent-fill-700'],
            'a white glyph on a success fill' => ['on-brand', 'brand-success-fill'],
            'a white glyph on a danger fill' => ['on-brand', 'brand-danger-fill'],
            // Bootstrap's utilities -- `.text-success`, `.text-danger`, a link
            // -- paint from these triplets and not from the variables beside
            // them. `--bs-success-rgb` was left at its light value and put
            // #146C43 on a dark panel at 2.65, which no assertion about
            // `--brand-success` could have noticed.
            'Bootstrap\'s success utility' => ['bs-success-rgb', 'surface-raised'],
            'Bootstrap\'s danger utility' => ['bs-danger-rgb', 'surface-raised'],
            'a Bootstrap link' => ['bs-link-color-rgb', 'surface-raised'],
        ];

        $graphic = [
            'ornament on a panel' => ['ink-faint', 'surface-raised'],
            'an ink chip against the panel it sits on' => ['brand-ink-chip', 'surface-raised'],
            'an accent fill against the panel it sits on' => ['brand-accent-fill', 'surface-raised'],
        ];

        $cases = [];

        foreach ($text as $what => [$ink, $ground]) {
            $cases[$what] = [$ink, $ground, self::TEXT, 'text'];
        }

        foreach ($graphic as $what => [$ink, $ground]) {
            $cases[$what] = [$ink, $ground, self::GRAPHIC, 'graphic'];
        }

        return $cases;
    }

    #[DataProvider('pairs')]
    public function testTheLightPaletteClearsIt(string $ink, string $ground, float $bar): void
    {
        $palette = self::palette();
        $measured = Palette::ratio($palette->light($ink), $palette->light($ground));

        self::assertGreaterThanOrEqual($bar, $measured, sprintf(
            'light: --%s on --%s is %.2f, under %.1f (#%s on #%s)',
            $ink,
            $ground,
            $measured,
            $bar,
            $palette->light($ink),
            $palette->light($ground),
        ));
    }

    #[DataProvider('pairs')]
    public function testTheDarkPaletteClearsIt(string $ink, string $ground, float $bar): void
    {
        $palette = self::palette();
        $measured = Palette::ratio($palette->dark($ink), $palette->dark($ground));

        self::assertGreaterThanOrEqual($bar, $measured, sprintf(
            'dark: --%s on --%s is %.2f, under %.1f (#%s on #%s)',
            $ink,
            $ground,
            $measured,
            $bar,
            $palette->dark($ink),
            $palette->dark($ground),
        ));
    }

    /**
     * The bands have to read as bands.
     *
     * Not a WCAG rule and deliberately a low bar -- this is the bug where the
     * header, the search band and the footer were set to 1.01 against the page
     * and the site quietly lost its bars. A separation this small is invisible
     * as a requirement and obvious as a mistake, which is why it is written
     * down rather than remembered.
     */
    public function testTheBandIsDistinguishableFromThePageInBothPalettes(): void
    {
        $palette = self::palette();

        foreach (['light', 'dark'] as $mode) {
            $band = $palette->{$mode}('brand-ink-fill');
            $page = $palette->{$mode}('surface');

            self::assertGreaterThanOrEqual(1.2, Palette::ratio($band, $page), sprintf(
                '%s: the band #%s is indistinguishable from the page #%s',
                $mode,
                $band,
                $page,
            ));
        }
    }

    /**
     * Depth is the surface ladder, and it has to run the right way.
     *
     * The answer to a question F1.2 (#133) asked: thirteen `box-shadow`s in
     * this file are dark rgba, which on a dark ground reads as nothing. Rather
     * than re-tune thirteen hand-picked values, dark takes its depth the way
     * dark interfaces do -- a raised surface is *lighter* than the page and a
     * sunken one darker, with shadow left as a supplement rather than the cue.
     *
     * That only works while the ladder keeps its order. It climbs the same way
     * in both -- a raised surface is lighter than the page and a sunken one
     * darker, in light as well as dark -- which is worth asserting precisely
     * because it is easy to assume the light palette runs the other way.
     */
    public function testTheSurfaceLadderClimbsAwayFromTheGroundInBothPalettes(): void
    {
        $palette = self::palette();

        foreach (['light', 'dark'] as $mode) {
            $sunken = self::lightness($palette->{$mode}('surface-sunken'));
            $page = self::lightness($palette->{$mode}('surface'));
            $raised = self::lightness($palette->{$mode}('surface-raised'));

            self::assertTrue(
                $raised > $page && $page > $sunken,
                sprintf(
                    '%s: the ladder is out of order -- sunken %.3f, page %.3f, raised %.3f',
                    $mode,
                    $sunken,
                    $page,
                    $raised,
                ),
            );
        }
    }

    /** Relative luminance, for questions about order rather than contrast. */
    private static function lightness(string $hex): float
    {
        return round(Palette::ratio($hex, '000000'), 4);
    }

    /**
     * A fill must not follow the ink it was split from.
     *
     * Every `-fill` is `var(--brand-accent)` and friends in `:root`, so a dark
     * block that redefines the ink and forgets the fill drags the fill with it.
     * That is exactly what turned every accent button into pale teal carrying
     * white at 2.20, and it is invisible in a diff.
     */
    public function testEveryFillIsPinnedApartFromItsInkInTheDarkPalette(): void
    {
        $palette = self::palette();

        foreach (['brand-accent', 'brand-success', 'brand-danger'] as $ink) {
            self::assertNotSame(
                $palette->dark($ink),
                $palette->dark($ink . '-fill'),
                sprintf('dark: --%s-fill has followed --%s instead of being pinned', $ink, $ink),
            );
        }
    }
}
