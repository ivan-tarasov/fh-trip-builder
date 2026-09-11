<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TripBuilder\Service\PostImageSweep;
use TripBuilder\View\Airside\PostImageSet;

/**
 * Which keys a post still reaches.
 *
 * Every assertion here is really about deletion. A name this fails to produce
 * is a file `airside:prune` will offer to remove, and the staging directory is
 * not committed -- so the bucket holds the only copy and a wrong answer cannot
 * be taken back.
 */
final class PostImageSweepTest extends TestCase
{
    private const string PREFIX = 'images/airside/';

    /** @return array{hero: ?string, body: string, author: string} */
    private static function post(
        ?string $hero = null,
        string $body = '',
        string $author = 'A Test Writer',
    ): array {
        return ['hero' => $hero, 'body' => $body, 'author' => $author];
    }

    /**
     * @param list<array{hero: ?string, body: string, author: string}> $posts
     * @return list<string>
     */
    private static function reachable(array $posts): array
    {
        return PostImageSweep::reachableFor($posts, self::PREFIX);
    }

    /*
    |--------------------------------------------------------------------------
    | The guard, which is the only one that cannot be recovered from
    |--------------------------------------------------------------------------
    */

    public function testNoPostsIsRefusedRatherThanTreatedAsNothingReachable(): void
    {
        $this->expectException(RuntimeException::class);

        self::reachable([]);
    }

    /*
    |--------------------------------------------------------------------------
    | A hero, which is stored seven times
    |--------------------------------------------------------------------------
    */

    public function testAHeroReachesItsCanonicalName(): void
    {
        self::assertContains(
            self::PREFIX . 'wing.3f9a2b1c.jpg',
            self::reachable([self::post(hero: 'wing.3f9a2b1c.jpg')]),
        );
    }

    public function testAHeroReachesEveryVariantItIsStoredAs(): void
    {
        $reachable = self::reachable([self::post(hero: 'wing.3f9a2b1c.jpg')]);

        foreach (PostImageSet::all('wing.3f9a2b1c.jpg') as $variant) {
            self::assertContains(self::PREFIX . $variant['name'], $reachable, $variant['name']);
        }
    }

    public function testAHeroReachesTheUnsuffixedNamePlusEverySize(): void
    {
        // Seven, and the count matters: this is what the uploader sends, so a
        // sweep that expected six would delete one of them every run.
        $sizes = count(PostImageSet::WIDTHS) + count(PostImageSet::SQUARES);

        self::assertCount(
            $sizes + 1,
            array_filter(
                self::reachable([self::post(hero: 'wing.3f9a2b1c.jpg', author: '!!!')]),
                static fn(string $key): bool => str_contains($key, 'wing.3f9a2b1c'),
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Body images and portraits, which are stored once
    |--------------------------------------------------------------------------
    */

    public function testABodyImageIsReached(): void
    {
        self::assertContains(
            self::PREFIX . 'diagram.png',
            self::reachable([self::post(body: 'Look: ![A diagram](diagram.png)')]),
        );
    }

    public function testABodyImageGetsNoVariants(): void
    {
        // The renderer asks for it by the name the author typed and emits no
        // `srcset`, so a `.750.` copy of it was never uploaded and treating
        // one as reachable would hide a real orphan.
        self::assertNotContains(
            self::PREFIX . 'diagram.750.png',
            self::reachable([self::post(body: '![A diagram](diagram.png)')]),
        );
    }

    public function testAllThreeCandidatePortraitsAreReached(): void
    {
        $reachable = self::reachable([self::post(author: 'A Test Writer')]);

        // Two of the three do not exist, which costs nothing. Guessing which
        // one does is exactly what this must not do.
        foreach (['jpg', 'png', 'webp'] as $extension) {
            self::assertContains(self::PREFIX . 'authors/a-test-writer.' . $extension, $reachable);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Across posts
    |--------------------------------------------------------------------------
    */

    public function testTwoPostsSharingAPortraitNameItOnce(): void
    {
        $reachable = self::reachable([self::post(), self::post()]);

        self::assertSame(array_values(array_unique($reachable)), $reachable);
    }

    public function testAPostWithNoImagesStillReachesItsAuthor(): void
    {
        // The point being that an empty result is never the right answer for a
        // real post, so a sweep can trust a short list rather than fear it.
        self::assertNotSame([], self::reachable([self::post()]));
    }

    public function testOneRetiredHeroDoesNotKeepAnotherAlive(): void
    {
        $reachable = self::reachable([self::post(hero: 'wing.aaaaaaaa.jpg')]);

        self::assertNotContains(self::PREFIX . 'wing.bbbbbbbb.jpg', $reachable);
    }
}
