<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View\Airside;

use PHPUnit\Framework\TestCase;
use TripBuilder\View\Airside\PostImageSet;

/**
 * The names one image is stored under.
 *
 * Written before there is a bucket, and that is the point: all of this is
 * arithmetic on a string, so none of it needs a credential, a network or an
 * image library to be right.
 */
final class PostImageSetTest extends TestCase
{
    private ?string $cdn = null;

    protected function setUp(): void
    {
        $this->cdn = $_ENV['AWS_CLOUDFRONT'] ?? null;
        $_ENV['AWS_CLOUDFRONT'] = 'cdn.example.net';
    }

    protected function tearDown(): void
    {
        if ($this->cdn === null) {
            unset($_ENV['AWS_CLOUDFRONT']);
        } else {
            $_ENV['AWS_CLOUDFRONT'] = $this->cdn;
        }
    }

    public function testACanonicalNameCarriesTheStemTheHashAndTheExtension(): void
    {
        $name = PostImageSet::canonical('Wing Over Cloud.JPG', 'some bytes');

        self::assertMatchesRegularExpression('/^wing-over-cloud\.[0-9a-f]{8}\.jpg$/', $name);
    }

    /**
     * The hash is of the bytes, not of the name.
     *
     * So the same picture uploaded twice under different names lands on one
     * key and the second upload costs nothing -- and a changed picture lands on
     * a different key, which is what removes cache invalidation from the
     * problem entirely.
     */
    public function testTheSamePictureUnderTwoNamesHashesTheSame(): void
    {
        $a = PostImageSet::canonical('wing.jpg', 'identical bytes');
        $b = PostImageSet::canonical('wing.jpg', 'identical bytes');
        $c = PostImageSet::canonical('wing.jpg', 'different bytes');

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }

    public function testAVariantPutsItsSizeBeforeTheExtension(): void
    {
        self::assertSame('wing.3f9a2b1c.640.jpg', PostImageSet::variant('wing.3f9a2b1c.jpg', 640));
    }

    /**
     * A square is marked, and it has to be.
     *
     * Without the `s`, a 320 landscape and a 320 square are one name. Whichever
     * uploaded second would win, and nothing would say so -- the in-body card
     * would simply start showing a letterboxed photograph.
     */
    public function testASquareCannotCollideWithALandscapeOfTheSameSize(): void
    {
        self::assertNotSame(
            PostImageSet::variant('wing.3f9a2b1c.jpg', 320),
            PostImageSet::variant('wing.3f9a2b1c.jpg', 320, true),
        );
        self::assertSame('wing.3f9a2b1c.s320.jpg', PostImageSet::variant('wing.3f9a2b1c.jpg', 320, true));
    }

    public function testEveryVariantIsNamedAndNoneTwice(): void
    {
        $names = array_column(PostImageSet::all('wing.3f9a2b1c.jpg'), 'name');

        self::assertCount(count(PostImageSet::WIDTHS) + count(PostImageSet::SQUARES), $names);
        self::assertSame($names, array_unique($names), 'two variants sharing a name would overwrite each other');
    }

    public function testTheSrcsetNamesEveryWidthWithItsWidth(): void
    {
        $srcset = PostImageSet::srcset('wing.3f9a2b1c.jpg');

        foreach (PostImageSet::WIDTHS as $width) {
            self::assertStringContainsString(
                '//cdn.example.net/images/airside/wing.3f9a2b1c.' . $width . '.jpg ' . $width . 'w',
                $srcset,
            );
        }
    }

    public function testTheSquareSrcsetNamesOnlySquares(): void
    {
        $srcset = PostImageSet::squareSrcset('wing.3f9a2b1c.jpg');

        self::assertStringContainsString('wing.3f9a2b1c.s160.jpg 160w', $srcset);
        self::assertStringContainsString('wing.3f9a2b1c.s320.jpg 320w', $srcset);
        self::assertStringNotContainsString('.750.', $srcset);
    }

    /**
     * A name that would not survive being a URL does not become one.
     */
    public function testAnAwkwardNameIsReducedToSomethingAUrlCanHold(): void
    {
        $name = PostImageSet::canonical('Фото / крыло!.jpeg', 'bytes');

        self::assertMatchesRegularExpression('/^[a-z0-9-]*\.[0-9a-f]{8}\.jpeg$/', $name);
    }

    public function testANameOfNothingStillGetsOne(): void
    {
        self::assertStringStartsWith('image.', PostImageSet::canonical('!!!.jpg', 'bytes'));
    }
}
