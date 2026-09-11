<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Service;

use TripBuilder\Repository\PostImageRepository;
use TripBuilder\Repository\PostRepository;
use TripBuilder\Service\PostImageSweep;
use TripBuilder\Service\PostImageUploader;
use TripBuilder\Tests\Fake\Bucket;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The sweep against real posts and a bucket that only remembers.
 *
 * `reachableFor()` is tested on plain arrays elsewhere. What is left here is
 * the wiring, which is the part that cannot be checked without rows: that the
 * set built from the database is the one the orphan lists are subtracted from,
 * and that a real post's hero really does keep its variants alive.
 */
final class PostImageSweepTest extends IntegrationTestCase
{
    private const string SLUG = 'zzp-sweep-test';
    private const string HERO = 'sweep.a1b2c3d4.jpg';

    private Bucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->bucket = new Bucket();

        new PostRepository($this->connection())->store(
            self::SLUG,
            ['published_at' => '2026-01-01', 'author' => 'A Test Writer', 'hero' => self::HERO],
            [
                'title' => 'A sweep fixture',
                'summary' => 'Written to be deleted.',
                'hero_alt' => 'Nothing in particular',
                'body' => 'A paragraph, and ![a diagram](sweep-diagram.png) inside it.',
            ],
        );
    }

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        new PostRepository($this->connection())->delete(self::SLUG);
        $this->connection()->execute('DELETE FROM post_images WHERE file LIKE ?', ['zzp-sweep%']);
    }

    private function sweep(): PostImageSweep
    {
        return new PostImageSweep(
            $this->bucket,
            new PostRepository($this->connection()),
            new PostImageRepository($this->connection()),
        );
    }

    private static function key(string $file): string
    {
        return PostImageUploader::prefix() . '/' . $file;
    }

    public function testARealPostsHeroIsReachable(): void
    {
        self::assertContains(self::key(self::HERO), $this->sweep()->reachable());
    }

    public function testARealPostsHeroKeepsItsVariants(): void
    {
        self::assertContains(self::key('sweep.a1b2c3d4.750.jpg'), $this->sweep()->reachable());
    }

    public function testAnImageInARealBodyIsReachable(): void
    {
        self::assertContains(self::key('sweep-diagram.png'), $this->sweep()->reachable());
    }

    public function testAnObjectNoPostNamesIsAnOrphan(): void
    {
        $this->bucket->put(self::key('zzp-sweep-nobody-wants-this.jpg'), 'x', 'image/jpeg');

        self::assertContains(self::key('zzp-sweep-nobody-wants-this.jpg'), $this->sweep()->orphanKeys());
    }

    public function testAReachableObjectIsNotAnOrphan(): void
    {
        $this->bucket->put(self::key(self::HERO), 'x', 'image/jpeg');

        self::assertNotContains(self::key(self::HERO), $this->sweep()->orphanKeys());
    }

    /**
     * Objects outside the prefix are never even looked at.
     *
     * The carrier logos and POI cards share this bucket and predate Airside by
     * years. A sweep that listed the whole bucket would find every one of them
     * unreachable, because no post names a supplier logo.
     */
    public function testNothingOutsideTheAirsidePrefixIsConsidered(): void
    {
        $this->bucket->put('images/suppliers/AC.png', 'x', 'image/png');

        self::assertNotContains('images/suppliers/AC.png', $this->sweep()->orphanKeys());
    }

    public function testARowForAFileNoPostNamesIsAnOrphan(): void
    {
        new PostImageRepository($this->connection())->store('zzp-sweep-stale.jpg', 100, 100);

        self::assertContains('zzp-sweep-stale.jpg', $this->sweep()->orphanRows());
    }

    public function testRemovingTakesTheObjectAndTheRow(): void
    {
        $this->bucket->put(self::key('zzp-sweep-gone.jpg'), 'x', 'image/jpeg');
        new PostImageRepository($this->connection())->store('zzp-sweep-gone.jpg', 100, 100);

        $this->sweep()->remove([self::key('zzp-sweep-gone.jpg')], ['zzp-sweep-gone.jpg']);

        self::assertFalse($this->bucket->has(self::key('zzp-sweep-gone.jpg')));
        self::assertArrayNotHasKey('zzp-sweep-gone.jpg', new PostImageRepository($this->connection())->all());
    }
}
