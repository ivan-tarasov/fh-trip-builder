<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TripBuilder\Aws\ObjectStore;
use TripBuilder\Service\ImageResizer;
use TripBuilder\Service\PostImageUploader;
use TripBuilder\View\Airside\PostImageSet;

/**
 * What the uploader sends, and more to the point what it does not.
 */
#[RequiresPhpExtension('gd')]
final class PostImageUploaderTest extends TestCase
{
    private Bucket $bucket;
    private PostImageUploader $uploader;

    protected function setUp(): void
    {
        $this->bucket = new Bucket();
        $this->uploader = new PostImageUploader($this->bucket, new ImageResizer());
    }

    private static function picture(int $width = 2000, int $height = 1200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 20, 90, 160));

        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public function testItSendsTheOriginalAndEveryVariant(): void
    {
        $bytes = self::picture();
        $canonical = PostImageSet::canonical('wing.jpg', $bytes);

        $sent = $this->uploader->upload($canonical, $bytes);

        // Four widths, two squares, and the un-suffixed name the `src` uses.
        self::assertCount(count(PostImageSet::WIDTHS) + count(PostImageSet::SQUARES) + 1, $sent);
    }

    public function testTheFirstThingSentIsTheUnsuffixedName(): void
    {
        $bytes = self::picture();
        $canonical = PostImageSet::canonical('wing.jpg', $bytes);

        self::assertSame(
            PostImageUploader::prefix() . '/' . $canonical,
            $this->uploader->upload($canonical, $bytes)[0],
        );
    }

    public function testTheUnsuffixedNameHoldsTheOriginalBytes(): void
    {
        $bytes = self::picture();
        $canonical = PostImageSet::canonical('wing.jpg', $bytes);
        $this->uploader->upload($canonical, $bytes);

        self::assertSame($bytes, $this->bucket->objects[PostImageUploader::prefix() . '/' . $canonical]);
    }

    public function testASecondRunSendsNothing(): void
    {
        $bytes = self::picture();
        $canonical = PostImageSet::canonical('wing.jpg', $bytes);

        $this->uploader->upload($canonical, $bytes);
        $before = $this->bucket->puts;

        self::assertSame([], $this->uploader->upload($canonical, $bytes));
        self::assertSame($before, $this->bucket->puts);
    }

    public function testItFillsOnlyTheGap(): void
    {
        $bytes = self::picture();
        $canonical = PostImageSet::canonical('wing.jpg', $bytes);

        $this->uploader->upload($canonical, $bytes);
        $one = PostImageUploader::prefix() . '/' . PostImageSet::variant($canonical, 960);
        unset($this->bucket->objects[$one]);

        self::assertSame([$one], $this->uploader->upload($canonical, $bytes));
    }

    public function testEverythingIsSentToBeCachedForever(): void
    {
        $bytes = self::picture();
        $this->uploader->upload(PostImageSet::canonical('wing.jpg', $bytes), $bytes);

        self::assertSame([ObjectStore::IMMUTABLE], array_values(array_unique($this->bucket->cacheControl)));
    }

    public function testTheVariantsAreActuallyTheSizeTheirNameClaims(): void
    {
        $bytes = self::picture();
        $canonical = PostImageSet::canonical('wing.jpg', $bytes);
        $this->uploader->upload($canonical, $bytes);

        $key = PostImageUploader::prefix() . '/' . PostImageSet::variant($canonical, 750);

        self::assertSame(750, ImageResizer::dimensions($this->bucket->objects[$key])['width']);
    }

    public function testTheSquaresAreSquare(): void
    {
        $bytes = self::picture();
        $canonical = PostImageSet::canonical('wing.jpg', $bytes);
        $this->uploader->upload($canonical, $bytes);

        $key = PostImageUploader::prefix() . '/' . PostImageSet::variant($canonical, 320, true);
        $size = ImageResizer::dimensions($this->bucket->objects[$key]);

        self::assertSame([320, 320], [$size['width'], $size['height']]);
    }

    public function testABodyImageGoesUpUnderItsOwnNameAndAtOneSize(): void
    {
        $bytes = self::picture();

        self::assertSame(
            PostImageUploader::prefix() . '/diagram.png',
            $this->uploader->uploadOne('diagram.png', $bytes),
        );
        self::assertCount(1, $this->bucket->objects);
    }

    public function testABodyImageAlreadyThereIsNotSentAgain(): void
    {
        $bytes = self::picture();
        $this->uploader->uploadOne('diagram.png', $bytes);

        self::assertNull($this->uploader->uploadOne('diagram.png', $bytes));
        self::assertSame(1, $this->bucket->puts);
    }
}

/** A bucket that remembers, so the test can ask what reached it. */
final class Bucket implements ObjectStore
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $cacheControl = [];

    public int $puts = 0;

    public function has(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function put(
        string $key,
        string $contents,
        string $contentType,
        string $cacheControl = ObjectStore::IMMUTABLE,
    ): void {
        $this->objects[$key] = $contents;
        $this->cacheControl[] = $cacheControl;
        $this->puts++;
    }
}
