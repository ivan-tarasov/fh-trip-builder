<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\PostImageRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * How big each Airside picture is.
 *
 * The table exists because the page can no longer measure the file: A8.6 sent
 * the images to a bucket, so `getimagesize()` against the staging directory
 * returns false on a deployed site and the `width` and `height` attributes are
 * dropped without anything erroring.
 */
final class PostImageRepositoryTest extends IntegrationTestCase
{
    private const string SENTINEL = 'zzp-size-test';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM post_images WHERE file LIKE ?', [self::SENTINEL . '%']);
    }

    private function images(): PostImageRepository
    {
        return new PostImageRepository($this->connection());
    }

    public function testASizeIsRecordedAndReadBack(): void
    {
        $this->images()->store(self::SENTINEL . '-a.jpg', 1500, 844);

        self::assertSame([1500, 844], $this->images()->all()[self::SENTINEL . '-a.jpg'] ?? null);
    }

    /**
     * A replaced picture moves its row rather than failing on the key.
     *
     * Only heroes are hashed. A body image keeps the name the author typed
     * when its contents change, so the second import of a re-cropped file
     * arrives at a key that already exists.
     */
    public function testReimportingAReplacedFileCorrectsTheSize(): void
    {
        $this->images()->store(self::SENTINEL . '-b.png', 800, 600);
        $this->images()->store(self::SENTINEL . '-b.png', 1200, 400);

        self::assertSame([1200, 400], $this->images()->all()[self::SENTINEL . '-b.png'] ?? null);
    }

    public function testTheKeyIsTheFileNameAndNotThePost(): void
    {
        // Two posts naming one picture describe the same pixels, so there is
        // one row and not one per post.
        $this->images()->store(self::SENTINEL . '-c.jpg', 960, 540);
        $this->images()->store(self::SENTINEL . '-c.jpg', 960, 540);

        $found = array_filter(
            array_keys($this->images()->all()),
            static fn(string $file): bool => $file === self::SENTINEL . '-c.jpg',
        );

        self::assertCount(1, $found);
    }

    public function testWidthAndHeightComeBackAsIntegers(): void
    {
        $this->images()->store(self::SENTINEL . '-d.jpg', 480, 270);

        // The renderer puts these straight into attributes, and HtmlElement's
        // escaper only takes strings -- a value that arrived as a string from
        // the driver would still cast, but one that arrived as null would not.
        self::assertSame(
            [true, true],
            array_map(is_int(...), $this->images()->all()[self::SENTINEL . '-d.jpg']),
        );
    }

    public function testForgettingAFileRemovesIt(): void
    {
        $this->images()->store(self::SENTINEL . '-e.jpg', 300, 300);
        $this->images()->delete(self::SENTINEL . '-e.jpg');

        self::assertArrayNotHasKey(self::SENTINEL . '-e.jpg', $this->images()->all());
    }
}
