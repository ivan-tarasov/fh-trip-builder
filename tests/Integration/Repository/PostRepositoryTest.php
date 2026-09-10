<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\PostRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The Airside tables, exercised against a real database.
 *
 * Doubling as the only proof the schema is what its config meant. Two things
 * in `post_translations` cannot be checked by reading the file: the composite
 * `slug, locale` primary key, and `'length' => null` on `body`, which is how
 * this schema spells a bare `TEXT`. Both are asserted here by round-tripping
 * values that a wrong answer would reject -- two locales of one slug, and a
 * body far longer than any `varchar` would hold.
 */
final class PostRepositoryTest extends IntegrationTestCase
{
    private const string SENTINEL = 'zzp-airside-test';

    protected function tearDown(): void
    {
        // Nothing was written, so there is nothing to tidy -- and a skip
        // raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach (['post_translations', 'posts'] as $table) {
            $this->connection()->execute(
                'DELETE FROM ' . $table . ' WHERE slug LIKE ?',
                [self::SENTINEL . '%'],
            );
        }
    }

    private function repository(): PostRepository
    {
        return new PostRepository($this->connection());
    }

    /**
     * `array_key_exists` and not `??`, because two of these are nullable and
     * an override of null is the case worth testing -- `??` would fall through
     * to the default and quietly test nothing.
     *
     * @param array<string, mixed> $overrides
     */
    private function write(string $suffix, array $overrides = []): string
    {
        $slug = self::SENTINEL . '-' . $suffix;

        $field = static fn(string $name, mixed $default): mixed
            => array_key_exists($name, $overrides) ? $overrides[$name] : $default;

        $this->repository()->store(
            $slug,
            [
                'published_at' => $field('published_at', '2026-03-01 09:00:00'),
                'author' => $field('author', 'A Writer'),
                'hero' => $field('hero', 'img/airside/one.jpg'),
            ],
            [
                'title' => $field('title', 'A post'),
                'summary' => $field('summary', 'One sentence about travel.'),
                'hero_alt' => $field('hero_alt', 'A window seat at altitude'),
                'body' => $field('body', "## A heading\n\nSome prose.\n"),
            ],
        );

        return $slug;
    }

    public function testAPostReadsBackTheWayItWasWritten(): void
    {
        $post = $this->repository()->find($this->write('one'));

        self::assertNotNull($post);
        self::assertSame('A post', $post['title']);
        self::assertSame('A Writer', $post['author']);
        self::assertSame('img/airside/one.jpg', $post['hero']);
        self::assertSame('A window seat at altitude', $post['hero_alt']);
        self::assertSame('2026-03-01 09:00:00', $post['published_at']);
    }

    /**
     * `body` is a real TEXT, which reading the config cannot tell you.
     *
     * `'length' => null` is how this schema asks for one, and the installer
     * emits the length parens only when `length` is truthy. A wrong answer
     * here is a silent truncation, so the assertion is a body longer than any
     * `varchar` in the tree.
     */
    public function testALongBodySurvivesTheRoundTrip(): void
    {
        $long = str_repeat("Paragraph about travelling somewhere.\n\n", 400);

        $post = $this->repository()->find($this->write('long', ['body' => $long]));

        self::assertNotNull($post);
        self::assertSame(strlen($long), strlen($post['body']));
    }

    /**
     * And the primary key really is composite, which the same file cannot show.
     */
    public function testOneSlugHoldsTwoLocalesAtOnce(): void
    {
        $slug = $this->write('locales');

        $this->repository()->store(
            $slug,
            ['published_at' => '2026-03-01 09:00:00', 'author' => 'A Writer', 'hero' => null],
            ['title' => 'Un article', 'summary' => 'Une phrase.', 'hero_alt' => null, 'body' => 'Du texte.'],
            'fr',
        );

        self::assertSame('A post', $this->repository()->find($slug)['title'] ?? null);
        self::assertSame('Un article', $this->repository()->find($slug, 'fr')['title'] ?? null);
    }

    public function testAHeroIsOptional(): void
    {
        $post = $this->repository()->find($this->write('nohero', ['hero' => null, 'hero_alt' => null]));

        self::assertNotNull($post);
        self::assertNull($post['hero']);
        self::assertNull($post['hero_alt']);
    }

    /**
     * Re-importing unchanged words must not claim an edit.
     */
    public function testStoringTheSameWordsLeavesTheDateAlone(): void
    {
        $slug = $this->write('same');
        $first = $this->repository()->find($slug)['updated_at'];

        $changed = $this->repository()->store(
            $slug,
            ['published_at' => '2026-03-01 09:00:00', 'author' => 'A Writer', 'hero' => 'img/airside/one.jpg'],
            [
                'title' => 'A post',
                'summary' => 'One sentence about travel.',
                'hero_alt' => 'A window seat at altitude',
                'body' => "## A heading\n\nSome prose.\n",
            ],
        );

        self::assertFalse($changed, 'nothing differed, so nothing was rewritten');
        self::assertSame($first, $this->repository()->find($slug)['updated_at']);
    }

    public function testChangingTheProseMovesTheDate(): void
    {
        $slug = $this->write('moved');

        // A second is the column's resolution, so a same-second rewrite would
        // read as unchanged and prove nothing.
        $this->connection()->execute(
            'UPDATE post_translations SET updated_at = ? WHERE slug = ?',
            ['2020-01-01 00:00:00', $slug],
        );

        $changed = $this->repository()->store(
            $slug,
            ['published_at' => '2026-03-01 09:00:00', 'author' => 'A Writer', 'hero' => 'img/airside/one.jpg'],
            [
                'title' => 'A post',
                'summary' => 'One sentence about travel.',
                'hero_alt' => 'A window seat at altitude',
                'body' => "## A heading\n\nDifferent prose.\n",
            ],
        );

        self::assertTrue($changed);
        self::assertNotSame('2020-01-01 00:00:00', $this->repository()->find($slug)['updated_at']);
    }

    /**
     * The date the post claims is not the date it was imported.
     */
    public function testReimportingDoesNotRepublishThePostAsToday(): void
    {
        $slug = $this->write('dated', ['published_at' => '2020-06-05 12:00:00']);

        $this->write('dated', ['published_at' => '2020-06-05 12:00:00', 'body' => 'Rewritten.']);

        self::assertSame('2020-06-05 12:00:00', $this->repository()->find($slug)['published_at']);
    }

    public function testTheSectionReadsNewestFirst(): void
    {
        $this->write('older', ['published_at' => '2026-01-01 09:00:00']);
        $this->write('newer', ['published_at' => '2026-05-01 09:00:00']);

        $mine = array_values(array_filter(
            array_keys($this->repository()->all()),
            static fn(string $slug): bool => str_starts_with($slug, self::SENTINEL),
        ));

        self::assertSame(
            [self::SENTINEL . '-newer', self::SENTINEL . '-older'],
            $mine,
        );
    }

    public function testPostsSharingADateStillComeBackInAFixedOrder(): void
    {
        $this->write('b-same', ['published_at' => '2026-04-01 09:00:00']);
        $this->write('a-same', ['published_at' => '2026-04-01 09:00:00']);

        $mine = array_values(array_filter(
            array_keys($this->repository()->all()),
            static fn(string $slug): bool => str_starts_with($slug, self::SENTINEL),
        ));

        self::assertSame([self::SENTINEL . '-a-same', self::SENTINEL . '-b-same'], $mine);
    }

    /**
     * Holding a post back has to remove it from every read at once.
     */
    public function testADisabledPostLeavesEveryReadButSlugs(): void
    {
        $slug = $this->write('held');
        $this->connection()->execute('UPDATE posts SET enabled = 0 WHERE slug = ?', [$slug]);

        self::assertNull($this->repository()->find($slug));
        self::assertArrayNotHasKey($slug, $this->repository()->all());
        self::assertContains($slug, $this->repository()->slugs(), 'the importer still has to see its row');
    }

    public function testTheNavAsksWhetherThereIsAnythingToLinkTo(): void
    {
        $slug = $this->write('any');
        self::assertTrue($this->repository()->any());

        $this->connection()->execute('UPDATE posts SET enabled = 0 WHERE slug = ?', [$slug]);
        self::assertSame(
            $this->repository()->all() !== [],
            $this->repository()->any(),
            'any() and all() have to agree about what counts as a post',
        );
    }

    public function testDeletingAPostTakesItsWordsWithIt(): void
    {
        $slug = $this->write('gone');
        $this->repository()->delete($slug);

        self::assertNull($this->repository()->find($slug));
        self::assertNotContains($slug, $this->repository()->slugs());
        self::assertSame([], $this->connection()->fetchAll(
            'SELECT slug FROM post_translations WHERE slug = ?',
            [$slug],
        ));
    }
}
