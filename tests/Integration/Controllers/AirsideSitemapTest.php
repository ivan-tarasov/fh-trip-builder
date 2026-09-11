<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Controllers;

use TripBuilder\Repository\PostRepository;
use TripBuilder\Repository\PostTagRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * What the sitemap promises a crawler about Airside.
 *
 * The reads rather than the XML: `SitemapController` turns each of these into
 * a `<loc>` with no filtering of its own, so the question worth asking is
 * whether the repositories hand it a page that exists.
 *
 * A sitemap is a promise. Offering a URL that answers 404 is worse than
 * offering nothing, because a crawler learns the site lies about its own
 * pages -- and both of the reads below have a near neighbour that would do
 * exactly that.
 */
final class AirsideSitemapTest extends IntegrationTestCase
{
    private const string SENTINEL = 'zzp-sitemap-test';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach (['post_tag_map', 'post_translations', 'posts'] as $table) {
            $this->connection()->execute(
                'DELETE FROM ' . $table . ' WHERE ' . ($table === 'post_tag_map' ? 'post' : 'slug') . ' LIKE ?',
                [self::SENTINEL . '%'],
            );
        }

        $this->connection()->execute('DELETE FROM post_tag_translations WHERE slug LIKE ?', [self::SENTINEL . '%']);
    }

    /** @param list<string> $tags */
    private function write(string $suffix, array $tags): string
    {
        $slug = self::SENTINEL . '-' . $suffix;
        $posts = new PostRepository($this->connection());
        $tagRepo = new PostTagRepository($this->connection());

        $posts->store(
            $slug,
            ['published_at' => '2026-03-01 09:00:00', 'author' => 'A Writer', 'hero' => null],
            ['title' => 'Post', 'summary' => 'A sentence.', 'hero_alt' => null, 'body' => 'Prose.'],
        );

        foreach ($tags as $tag) {
            $tagRepo->store($tag, 'Name');
        }

        $tagRepo->map($slug, $tags);

        return $slug;
    }

    public function testAPostIsOfferedToACrawler(): void
    {
        $slug = $this->write('listed', [self::SENTINEL . '-tag']);

        self::assertArrayHasKey($slug, new PostRepository($this->connection())->all());
    }

    /**
     * `all()` filters on `enabled` and `slugs()` deliberately does not, because
     * the importer needs to see a held-back row. The sitemap must use the
     * first: a post the site will not show is a page that answers 404.
     */
    public function testAHeldBackPostIsNotOffered(): void
    {
        $slug = $this->write('held', [self::SENTINEL . '-tag']);
        $this->connection()->execute('UPDATE posts SET enabled = 0 WHERE slug = ?', [$slug]);

        self::assertArrayNotHasKey($slug, new PostRepository($this->connection())->all());
        self::assertContains($slug, new PostRepository($this->connection())->slugs(), 'the importer still sees it');
    }

    /**
     * And a tag with nothing behind it is the same broken promise, which is
     * why the sitemap reads `inUse()` and not every stored name.
     */
    public function testATagWithNoPostsIsNotOffered(): void
    {
        $slug = $this->write('orphan', [self::SENTINEL . '-orphan-tag']);
        $tags = new PostTagRepository($this->connection());

        self::assertArrayHasKey(self::SENTINEL . '-orphan-tag', $tags->inUse());

        // The post goes; the name is still on the table until it is pruned.
        $tags->forget($slug);

        self::assertArrayNotHasKey(self::SENTINEL . '-orphan-tag', $tags->inUse());
        self::assertNotNull($tags->name(self::SENTINEL . '-orphan-tag'), 'the name outlives the map until pruned');
    }
}
