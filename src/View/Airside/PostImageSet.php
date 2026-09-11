<?php

declare(strict_types=1);

namespace TripBuilder\View\Airside;

/**
 * The sizes one post image is served at, and what each one is called.
 *
 * Pure: no filesystem, no network, no GD. Everything here is arithmetic on a
 * name, which is why it can be written and tested before there is a bucket to
 * put anything in.
 *
 * A row stores one canonical name -- `wing.3f9a2b1c.jpg` -- and a variant
 * inserts its size before the extension. The width is never in the row, so
 * adding a size later is a change here and a re-upload, not a migration.
 *
 * The hash in the middle is of the original's contents, which is what removes
 * cache invalidation from the problem: a changed image is a different name, so
 * nothing the CDN is holding can ever be stale.
 */
final class PostImageSet
{
    /**
     * Widths the landscape copies are cut to.
     *
     * A hub card draws about 240px and a post hero about 748px, each of which
     * wants a 2x copy for a dense screen. Four files, not the reference's
     * eight -- the widths above 1500 are for layouts this site does not have.
     */
    public const array WIDTHS = [320, 640, 750, 1500];

    /**
     * And the square ones, centre-cropped.
     *
     * `.airside-inline__image` is a fixed 4.5rem square with `object-fit:
     * cover`, so without these the in-body card downloads a 16:9 photograph in
     * order to throw most of it away. 160 covers the 72px slot, 320 covers it
     * on a dense screen.
     */
    public const array SQUARES = [160, 320];

    /** How much of the content hash goes in the name. */
    private const int HASH_LENGTH = 8;

    /**
     * The canonical name for an original: `wing.3f9a2b1c.jpg`.
     *
     * The hash is of the bytes and not of the file name, so two uploads of the
     * same picture under different names land on the same key and the second
     * costs nothing.
     */
    public static function canonical(string $file, string $contents): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $stem = self::slug(pathinfo($file, PATHINFO_FILENAME));

        return sprintf(
            '%s.%s.%s',
            $stem === '' ? 'image' : $stem,
            substr(hash('sha256', $contents), 0, self::HASH_LENGTH),
            $extension === '' ? 'jpg' : $extension,
        );
    }

    /**
     * One variant's name: `wing.3f9a2b1c.640.jpg`, or `.s320.` for a square.
     *
     * The `s` is what stops a 320 landscape and a 320 square colliding on one
     * name, which they otherwise would -- and the collision would be silent,
     * because whichever uploaded second would simply win.
     */
    public static function variant(string $canonical, int $size, bool $square = false): string
    {
        $extension = pathinfo($canonical, PATHINFO_EXTENSION);
        $stem = substr($canonical, 0, -(strlen($extension) + 1));

        return sprintf('%s.%s%d.%s', $stem, $square ? 's' : '', $size, $extension);
    }

    /**
     * Every name one original is stored under, widest last.
     *
     * @return list<array{name: string, size: int, square: bool}>
     */
    public static function all(string $canonical): array
    {
        $names = [];

        foreach (self::SQUARES as $size) {
            $names[] = ['name' => self::variant($canonical, $size, true), 'size' => $size, 'square' => true];
        }

        foreach (self::WIDTHS as $size) {
            $names[] = ['name' => self::variant($canonical, $size), 'size' => $size, 'square' => false];
        }

        return $names;
    }

    /**
     * A `srcset` for the landscape copies, as the attribute wants it.
     */
    public static function srcset(string $canonical): string
    {
        return implode(', ', array_map(
            static fn(int $width): string => PostImages::url(self::variant($canonical, $width)) . ' ' . $width . 'w',
            self::WIDTHS,
        ));
    }

    /** And for the squares, which the in-body card uses. */
    public static function squareSrcset(string $canonical): string
    {
        return implode(', ', array_map(
            static fn(int $size): string => PostImages::url(self::variant($canonical, $size, true)) . ' ' . $size . 'w',
            self::SQUARES,
        ));
    }

    /**
     * A name reduced to something a key can hold.
     *
     * The same rule `airside:import` applies to a post's file name, for the
     * same reason: the result ends up in a URL.
     */
    private static function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
    }
}
