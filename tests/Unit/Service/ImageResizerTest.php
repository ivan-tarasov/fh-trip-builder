<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TripBuilder\Service\ImageResizer;

/**
 * The resizer, on images made here rather than on fixtures.
 *
 * Drawn in the test because what each one has to prove is a property of its
 * pixels -- that the middle survived a crop, that a transparent corner is
 * still transparent -- and a committed JPEG would make those assertions about
 * a file nobody can see while reading the test.
 */
#[RequiresPhpExtension('gd')]
final class ImageResizerTest extends TestCase
{
    private ImageResizer $resizer;

    protected function setUp(): void
    {
        $this->resizer = new ImageResizer();
    }

    /** Three vertical bands, so a centre crop is provable: red, green, blue. */
    private static function banded(int $width = 300, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        $third = (int) ($width / 3);

        imagefilledrectangle($image, 0, 0, $third - 1, $height, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, $third, 0, $third * 2 - 1, $height, (int) imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, $third * 2, 0, $width, $height, (int) imagecolorallocate($image, 0, 0, 255));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array{width: int, height: int} */
    private static function sizeOf(string $bytes): array
    {
        return ImageResizer::dimensions($bytes);
    }

    public function testItScalesToTheWidthAskedFor(): void
    {
        self::assertSame(150, self::sizeOf($this->resizer->toWidth(self::banded(), 150))['width']);
    }

    public function testItKeepsTheProportions(): void
    {
        // 300x100 asked down to 150 is 150x50, not 150x100.
        self::assertSame(50, self::sizeOf($this->resizer->toWidth(self::banded(), 150))['height']);
    }

    public function testItRefusesToUpscale(): void
    {
        // A 1500 variant of a 300px original is 300px of picture in a bigger
        // file. The name still says 1500 because the srcset needs the file to
        // exist; the bytes stay honest.
        self::assertSame(300, self::sizeOf($this->resizer->toWidth(self::banded(), 1500))['width']);
    }

    public function testASquareIsSquare(): void
    {
        $size = self::sizeOf($this->resizer->toSquare(self::banded(), 80));

        self::assertSame([80, 80], [$size['width'], $size['height']]);
    }

    public function testTheSquareIsCroppedFromTheMiddle(): void
    {
        $square = imagecreatefromstring($this->resizer->toSquare(self::banded(), 60));
        self::assertNotFalse($square);

        // The middle band is green. A crop anchored top-left would be red.
        $colour = imagecolorsforindex($square, imagecolorat($square, 30, 30));

        self::assertGreaterThan(200, $colour['green']);
        self::assertLessThan(60, $colour['red']);
    }

    public function testASquareOfATallImageTakesItsWidth(): void
    {
        // 100 wide by 300 tall: the square can only be 100 a side, so asking
        // for 200 gets 100 rather than an upscale.
        self::assertSame(100, self::sizeOf($this->resizer->toSquare(self::banded(100, 300), 200))['width']);
    }

    public function testTransparencySurvivesAResize(): void
    {
        $image = imagecreatetruecolor(200, 200);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $out = imagecreatefromstring($this->resizer->toWidth($png, 100));
        self::assertNotFalse($out);

        // 127 is fully transparent. Without imagesavealpha on the canvas this
        // comes back 0 -- opaque black -- and the picture looks fine until it
        // is put on anything but a white page.
        self::assertSame(127, imagecolorsforindex($out, imagecolorat($out, 50, 50))['alpha']);
    }

    public function testItRefusesSomethingThatIsNotAnImage(): void
    {
        $this->expectException(RuntimeException::class);

        $this->resizer->toWidth('this is not a picture', 100);
    }
}
