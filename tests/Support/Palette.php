<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Support;

use RuntimeException;

/**
 * The two palettes, read out of the stylesheet that declares them.
 *
 * Read rather than restated. A copy of these values in a test would be a second
 * place to keep them, and the bug it was written to catch is precisely a value
 * in one place disagreeing with a value in another.
 *
 * Only the token layer is parsed, which is all the contrast questions need:
 * every colour that matters reaches the page through one of these names.
 */
final readonly class Palette
{
    /**
     * @param array<string, string> $base every `--x` declared in a `:root` block
     * @param array<string, string> $dark what `:root[data-theme="dark"]` changes
     */
    private function __construct(private array $base, private array $dark) {}

    public static function fromCss(string $css): self
    {
        $dark = self::declarations(self::block($css, ':root[data-theme="dark"]'));

        // Every plain `:root` block, because there is more than one and they
        // cascade -- the second holds the toggle's own measurements.
        $base = [];

        foreach (self::blocks($css, ':root') as $body) {
            $base = [...$base, ...self::declarations($body)];
        }

        if ($base === [] || $dark === []) {
            throw new RuntimeException('Neither palette could be read out of the stylesheet.');
        }

        return new self($base, $dark);
    }

    /** The light value of a token, as six hex digits. */
    public function light(string $token): string
    {
        return $this->resolve($token, $this->base);
    }

    /** And the dark one, which falls back to the light where nothing overrides. */
    public function dark(string $token): string
    {
        return $this->resolve($token, [...$this->base, ...$this->dark]);
    }

    /** @return list<string> every token name either palette declares */
    public function tokens(): array
    {
        return array_values(array_filter(
            array_keys($this->base),
            static fn(string $name): bool => !str_starts_with($name, 'd-'),
        ));
    }

    /**
     * WCAG's contrast ratio, 1 to 21.
     */
    public static function ratio(string $one, string $other): float
    {
        $a = self::luminance($one);
        $b = self::luminance($other);

        return round((max($a, $b) + 0.05) / (min($a, $b) + 0.05), 2);
    }

    /**
     * Follow `var()` until a colour comes out.
     *
     * A dark assignment is `--x: var(--d-x)`, and `--d-x` is declared in the
     * base block -- so a name that is not in the palette being resolved is
     * looked for there before giving up.
     *
     * @param array<string, string> $palette
     */
    private function resolve(string $token, array $palette, int $depth = 0): string
    {
        if ($depth > 10) {
            throw new RuntimeException(sprintf('`--%s` refers to itself.', $token));
        }

        $value = $palette[$token] ?? $this->base[$token] ?? null;

        if ($value === null) {
            throw new RuntimeException(sprintf('No token `--%s` in the stylesheet.', $token));
        }

        if (preg_match('/^var\(--([a-z0-9-]+)\)$/i', $value, $found) === 1) {
            return $this->resolve($found[1], $palette, $depth + 1);
        }

        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $found) === 1) {
            return strtoupper($found[1]);
        }

        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $found) === 1) {
            return strtoupper($found[1][0] . $found[1][0] . $found[1][1] . $found[1][1] . $found[1][2] . $found[1][2]);
        }

        // Bootstrap's utilities paint from bare `r, g, b` triplets rather than
        // from the variable beside them, so these are colours too -- and the
        // kind most easily left behind, because they look like data.
        if (preg_match('/^(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})$/', $value, $found) === 1) {
            return strtoupper(sprintf('%02x%02x%02x', (int) $found[1], (int) $found[2], (int) $found[3]));
        }

        throw new RuntimeException(sprintf('`--%s` is `%s`, which is not a plain colour.', $token, $value));
    }

    private static function block(string $css, string $selector): string
    {
        return self::blocks($css, $selector)[0] ?? '';
    }

    /** @return list<string> the body of every rule with exactly this selector */
    private static function blocks(string $css, string $selector): array
    {
        $found = [];
        $offset = 0;

        while (($at = strpos($css, $selector, $offset)) !== false) {
            $offset = $at + strlen($selector);
            $open = strpos($css, '{', $at);

            if ($open === false) {
                break;
            }

            // Only an exact selector: `:root` must not match `:root[data-theme]`
            // or `:root:not(...)`, which are the other palette.
            if (trim(substr($css, $at + strlen($selector), $open - $at - strlen($selector))) !== '') {
                continue;
            }

            $close = strpos($css, "\n}", $open);
            $found[] = substr($css, $open, $close === false ? null : $close - $open);
        }

        return $found;
    }

    /** @return array<string, string> */
    private static function declarations(string $body): array
    {
        preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $body, $found, PREG_SET_ORDER);

        $declarations = [];

        foreach ($found as $one) {
            $declarations[$one[1]] = trim($one[2]);
        }

        return $declarations;
    }

    private static function luminance(string $hex): float
    {
        $channels = [];

        foreach ([0, 2, 4] as $at) {
            $value = hexdec(substr($hex, $at, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
