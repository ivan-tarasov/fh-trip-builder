<?php

declare(strict_types=1);

namespace TripBuilder\Service;

use RuntimeException;
use TripBuilder\Aws\ObjectStore;
use TripBuilder\Repository\PostImageRepository;
use TripBuilder\Repository\PostRepository;
use TripBuilder\View\Airside\PostImages;
use TripBuilder\View\Airside\PostImageSet;

/**
 * What is in the bucket that nothing points at any more.
 *
 * Hashed names make the bucket safe to grow -- no object is ever stale -- but
 * not free. A hero replaced five times leaves five sets of seven variants, and
 * nothing in the repository records that the first four are unreachable.
 *
 * The posts are the truth here, and `post_images` deliberately is not. That
 * table is an index the importer writes, so a row can outlive the post that
 * caused it; treating it as the list of what matters would keep an orphan
 * alive because a stale row mentioned it. So both the objects and the rows are
 * checked against the same answer, worked out from the posts themselves.
 */
final readonly class PostImageSweep
{
    public function __construct(
        private ObjectStore $bucket,
        private PostRepository $posts,
        private PostImageRepository $images,
    ) {}

    /**
     * Every key a page could ask for, spelled as the bucket holds it.
     *
     * Three shapes, because the section stores three. A hero is a canonical
     * name plus the six sizes derived from it; a body image is whatever the
     * markdown names; an author's portrait is one of three candidate spellings
     * and all three are counted, because the two that do not exist cost
     * nothing and guessing which does is what `authorFiles()` exists to avoid.
     *
     * @return list<string>
     */
    public function reachable(): array
    {
        $posts = [];

        foreach ($this->posts->slugs() as $slug) {
            $post = $this->posts->find($slug);

            if ($post !== null) {
                $posts[] = $post;
            }
        }

        return self::reachableFor($posts, PostImageUploader::prefix() . '/');
    }

    /**
     * The same answer worked out from posts already in hand.
     *
     * Static and pure because this is the half worth being sure about: every
     * name it fails to produce is a file this command then offers to delete,
     * and the bucket is the only copy. Given plain arrays it can be tested
     * without a database, a bucket or a credential.
     *
     * Refuses an empty list rather than returning an empty one. With no posts
     * nothing is reachable and every object is an orphan, so an unreadable
     * table, a fresh database or a mistyped environment would all read as
     * "delete all of it" -- a wrong answer that cannot be taken back.
     *
     * @param list<array{hero: ?string, body: string, author: string}> $posts
     * @return list<string>
     */
    public static function reachableFor(array $posts, string $prefix): array
    {
        if ($posts === []) {
            throw new RuntimeException(
                'There are no posts, so nothing is reachable and everything would be an orphan.'
                . ' Refusing to sweep: the bucket holds the only copy of these files.',
            );
        }

        $keys = [];

        foreach ($posts as $post) {
            if ($post['hero'] !== null) {
                $keys[] = $prefix . $post['hero'];

                foreach (PostImageSet::all($post['hero']) as $variant) {
                    $keys[] = $prefix . $variant['name'];
                }
            }

            foreach (PostImages::inBody($post['body']) as $file) {
                $keys[] = $prefix . $file;
            }

            foreach (PostImages::authorFiles($post['author']) as $file) {
                $keys[] = $prefix . $file;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Objects under the prefix that nothing reaches.
     *
     * Refuses outright when there are no posts -- see `reachableFor()`, which
     * is where that guard lives and why.
     *
     * @return list<string>
     */
    public function orphanKeys(): array
    {
        $reachable = $this->reachable();

        return array_values(array_filter(
            $this->bucket->keysUnder(PostImageUploader::prefix() . '/'),
            static fn(string $key): bool => !in_array($key, $reachable, true),
        ));
    }

    /**
     * Rows in `post_images` describing a file nothing names any more.
     *
     * Swept for the same reason and at the same time as the objects: a row is
     * three columns and confuses nothing on its own, but leaving them means
     * the table slowly stops being a description of the section.
     *
     * @return list<string>
     */
    public function orphanRows(): array
    {
        $reachable = $this->reachable();
        $prefix = PostImageUploader::prefix() . '/';

        return array_values(array_filter(
            array_keys($this->images->all()),
            static fn(string $file): bool => !in_array($prefix . $file, $reachable, true),
        ));
    }

    /**
     * Remove what the two lists name.
     *
     * Objects first. A row without its object describes nothing and is
     * harmless; an object without its row is invisible to the next sweep,
     * because the sweep reads posts rather than rows -- but it would also be
     * invisible to anyone looking for what to clean up.
     *
     * @param list<string> $keys
     * @param list<string> $rows
     */
    public function remove(array $keys, array $rows): void
    {
        foreach ($keys as $key) {
            $this->bucket->delete($key);
        }

        foreach ($rows as $file) {
            $this->images->delete($file);
        }
    }
}
