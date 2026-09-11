<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\PostRepository;
use TripBuilder\Repository\PostTagRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * Tags, and the two reads they exist for: a listing page and related posts.
 */
final class PostTagRepositoryTest extends IntegrationTestCase
{
    private const string SENTINEL = 'zzp-tag-test';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach (['post_tag_map', 'post_translations', 'posts'] as $table) {
            $column = $table === 'post_tag_map' ? 'post' : 'slug';
            $this->connection()->execute(
                'DELETE FROM ' . $table . ' WHERE ' . $column . ' LIKE ?',
                [self::SENTINEL . '%'],
            );
        }

        $this->connection()->execute(
            'DELETE FROM post_tag_translations WHERE slug LIKE ?',
            [self::SENTINEL . '%'],
        );
    }

    private function tags(): PostTagRepository
    {
        return new PostTagRepository($this->connection());
    }

    private function posts(): PostRepository
    {
        return new PostRepository($this->connection());
    }

    /** @param list<string> $tags */
    private function write(string $suffix, string $published, array $tags): string
    {
        $slug = self::SENTINEL . '-' . $suffix;

        $this->posts()->store(
            $slug,
            ['published_at' => $published, 'author' => 'A Writer', 'hero' => null],
            ['title' => 'Post ' . $suffix, 'summary' => 'A sentence.', 'hero_alt' => null, 'body' => 'Prose.'],
        );

        foreach ($tags as $tag) {
            $this->tags()->store($tag, ucfirst(str_replace(self::SENTINEL . '-', '', $tag)));
        }

        $this->tags()->map($slug, $tags);

        return $slug;
    }

    public function testAPostsTagsComeBackByName(): void
    {
        $slug = $this->write('one', '2026-03-01 09:00:00', [self::SENTINEL . '-security', self::SENTINEL . '-packing']);

        // By name, not by the order the file listed them: a file reordered on a
        // later import would otherwise reorder the pills for no visible reason.
        self::assertSame(
            [self::SENTINEL . '-packing', self::SENTINEL . '-security'],
            array_keys($this->tags()->forPost($slug)),
        );
    }

    public function testAnUnknownTagHasNoName(): void
    {
        self::assertNull($this->tags()->name('zzp-no-such-tag'));
    }

    /**
     * Mapping replaces rather than adds to what was there.
     */
    public function testRemappingAPostDropsTheTagsItNoLongerCarries(): void
    {
        $slug = $this->write('two', '2026-03-01 09:00:00', [self::SENTINEL . '-a', self::SENTINEL . '-b']);

        $this->tags()->map($slug, [self::SENTINEL . '-b']);

        self::assertSame([self::SENTINEL . '-b'], array_keys($this->tags()->forPost($slug)));
    }

    /**
     * A name outliving its last post would be inherited by the next one.
     */
    public function testANameWithNoPostBehindItIsPruned(): void
    {
        $slug = $this->write('three', '2026-03-01 09:00:00', [self::SENTINEL . '-orphan']);

        $this->tags()->map($slug, []);
        $this->tags()->pruneUnused();

        self::assertNull($this->tags()->name(self::SENTINEL . '-orphan'));
    }

    public function testATagInUseCarriesItsCount(): void
    {
        $this->write('four', '2026-03-01 09:00:00', [self::SENTINEL . '-shared']);
        $this->write('five', '2026-03-02 09:00:00', [self::SENTINEL . '-shared']);

        self::assertSame(2, $this->tags()->inUse()[self::SENTINEL . '-shared']['posts'] ?? null);
    }

    public function testTheListingPageGetsThePostsCarryingOneTag(): void
    {
        $older = $this->write('six', '2026-01-01 09:00:00', [self::SENTINEL . '-listed']);
        $newer = $this->write('seven', '2026-05-01 09:00:00', [self::SENTINEL . '-listed']);
        $this->write('eight', '2026-06-01 09:00:00', [self::SENTINEL . '-other']);

        self::assertSame([$newer, $older], array_keys($this->posts()->taggedWith(self::SENTINEL . '-listed')));
    }

    /**
     * Two posts can share more than one tag, and the reader should meet the
     * second one once.
     */
    public function testAPostSharingTwoTagsIsOfferedOnlyOnce(): void
    {
        $both = [self::SENTINEL . '-x', self::SENTINEL . '-y'];
        $mine = $this->write('nine', '2026-03-01 09:00:00', $both);
        $theirs = $this->write('ten', '2026-04-01 09:00:00', $both);

        $related = $this->posts()->related($mine);

        self::assertSame([$theirs], array_keys($related));
    }

    public function testAPostIsNeverRelatedToItself(): void
    {
        $mine = $this->write('eleven', '2026-03-01 09:00:00', [self::SENTINEL . '-alone']);

        self::assertSame([], $this->posts()->related($mine));
    }

    public function testRelatedPostsComeBackNewestFirstAndWithinTheLimit(): void
    {
        $tag = [self::SENTINEL . '-many'];
        $mine = $this->write('twelve', '2026-01-01 09:00:00', $tag);
        $this->write('a-older', '2026-02-01 09:00:00', $tag);
        $mid = $this->write('b-mid', '2026-03-01 09:00:00', $tag);
        $newest = $this->write('c-newest', '2026-04-01 09:00:00', $tag);

        self::assertSame([$newest, $mid], array_keys($this->posts()->related($mine, 2)));
    }

    /**
     * Holding a post back has to remove it from these reads too, or the
     * `enabled` flag stops meaning anything the moment tags arrive.
     */
    public function testADisabledPostIsNeitherListedNorRelated(): void
    {
        $tag = [self::SENTINEL . '-held'];
        $mine = $this->write('thirteen', '2026-01-01 09:00:00', $tag);
        $other = $this->write('fourteen', '2026-02-01 09:00:00', $tag);

        $this->connection()->execute('UPDATE posts SET enabled = 0 WHERE slug = ?', [$other]);

        self::assertSame([], $this->posts()->related($mine));
        self::assertArrayNotHasKey($other, $this->posts()->taggedWith(self::SENTINEL . '-held'));
    }
}
