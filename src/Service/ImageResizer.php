<?php

declare(strict_types=1);

namespace TripBuilder\Service;

use GdImage;
use RuntimeException;

/**
 * Bytes in, smaller bytes out, in whatever format they arrived in.
 *
 * `gd` is needed and this is the only thing that needs it. That is on purpose:
 * a command somebody runs may depend on an extension that a web request must
 * not, and keeping the dependency in one file is what makes that checkable.
 */
final readonly class ImageResizer
{
    /**
     * High enough that the 1500 hero survives it, low enough to be worth
     * resizing for. Measured against the existing posts rather than picked:
     * above about 85 the file grows faster than the picture improves.
     */
    private const int QUALITY = 82;

    /** The longest edge scaled to `$width`, or the original if it is smaller. */
    public function toWidth(string $contents, int $width): string
    {
        [$image, $format] = self::open($contents);
        $from = imagesx($image);

        // Never upscale. A 1500 variant of an 800px original is 800px of
        // picture in a 1500px file, which is slower to send and no better to
        // look at. The `srcset` descriptor overstates it either way, and a
        // wrong descriptor is a smaller problem than a missing file.
        $target = min($width, $from);
        $height = (int) round(imagesy($image) * ($target / $from));

        return self::render(self::scaled($image, $target, max(1, $height), $format), $format);
    }

    /** The middle square, scaled to `$size` a side. */
    public function toSquare(string $contents, int $size): string
    {
        [$image, $format] = self::open($contents);
        $edge = min(imagesx($image), imagesy($image));
        $target = min($size, $edge);

        $square = self::blank($target, $target, $format);

        imagecopyresampled(
            $square,
            $image,
            0,
            0,
            // Centred, so a face in the middle of a landscape survives the crop.
            (int) round((imagesx($image) - $edge) / 2),
            (int) round((imagesy($image) - $edge) / 2),
            $target,
            $target,
            $edge,
            $edge,
        );

        return self::render($square, $format);
    }

    /**
     * The width and height of an original, without decoding all of it.
     *
     * @return array{width: int, height: int}
     */
    public static function dimensions(string $contents): array
    {
        $size = getimagesizefromstring($contents);

        if ($size === false) {
            throw new RuntimeException('That is not an image this can read.');
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    /** @return array{0: GdImage, 1: string} */
    private static function open(string $contents): array
    {
        $size = getimagesizefromstring($contents);
        $image = @imagecreatefromstring($contents);

        if ($size === false || !$image instanceof GdImage) {
            throw new RuntimeException('That is not an image this can read.');
        }

        return [$image, (string) image_type_to_extension($size[2], false)];
    }

    private static function scaled(GdImage $image, int $width, int $height, string $format): GdImage
    {
        $out = self::blank($width, $height, $format);

        imagecopyresampled($out, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $out;
    }

    /**
     * A canvas that keeps transparency where the format has any.
     *
     * Without this a PNG with an alpha channel comes back with a black
     * background, because a new true-colour image is opaque black and the
     * copy blends onto it rather than replacing it.
     */
    private static function blank(int $width, int $height, string $format): GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        if (in_array($format, ['png', 'gif', 'webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        }

        return $image;
    }

    private static function render(GdImage $image, string $format): string
    {
        ob_start();

        $written = match ($format) {
            'jpeg', 'jpg' => imagejpeg($image, null, self::QUALITY),
            'png' => imagepng($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image, null, self::QUALITY),
            default => false,
        };

        $bytes = (string) ob_get_clean();

        if (!$written || $bytes === '') {
            throw new RuntimeException(sprintf('A `%s` could not be written back out.', $format));
        }

        return $bytes;
    }
}
