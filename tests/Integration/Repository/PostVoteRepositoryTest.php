<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\PostVoteRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * Votes on posts, and the block they order.
 *
 * The block is the reason this exists, and the reason most of what follows is
 * about what `liked()` refuses to return: with no seeder, an untouched install
 * has no votes at all, and a "most liked" list assembled from nothing would be
 * a heading over an arbitrary order.
 */
final class PostVoteRepositoryTest extends IntegrationTestCase
{
    private const string SENTINEL = 'zzp-vote-test';
    private const string VOTER_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string VOTER_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM post_votes WHERE slug LIKE ?', [self::SENTINEL . '%']);
    }

    private function votes(): PostVoteRepository
    {
        return new PostVoteRepository($this->connection());
    }

    public function testAVoteIsRecordedAndCountedBack(): void
    {
        $this->votes()->record(self::SENTINEL . '-one', self::VOTER_A, true);

        self::assertSame(
            ['votes' => 1, 'helpful' => 1],
            $this->votes()->tallyFor(self::SENTINEL . '-one'),
        );
    }

    /**
     * One person, one vote: changing your mind moves your row.
     */
    public function testChangingYourMindDoesNotVoteTwice(): void
    {
        $slug = self::SENTINEL . '-mind';

        $this->votes()->record($slug, self::VOTER_A, true);
        $this->votes()->record($slug, self::VOTER_A, false);

        self::assertSame(['votes' => 1, 'helpful' => 0], $this->votes()->tallyFor($slug));
    }

    public function testTwoReadersAreTwoVotes(): void
    {
        $slug = self::SENTINEL . '-two';

        $this->votes()->record($slug, self::VOTER_A, true);
        $this->votes()->record($slug, self::VOTER_B, false);

        self::assertSame(['votes' => 2, 'helpful' => 1], $this->votes()->tallyFor($slug));
    }

    /**
     * Null and false are different answers: one draws no selection on the
     * thumbs and the other draws one.
     */
    public function testAReaderWhoHasNotVotedHasNoVerdict(): void
    {
        $slug = self::SENTINEL . '-verdict';

        self::assertNull($this->votes()->verdictOf($slug, self::VOTER_A));

        $this->votes()->record($slug, self::VOTER_A, false);

        self::assertFalse($this->votes()->verdictOf($slug, self::VOTER_A));
    }

    public function testAPostWithNoVotesTalliesZero(): void
    {
        self::assertSame(['votes' => 0, 'helpful' => 0], $this->votes()->tallyFor(self::SENTINEL . '-silent'));
    }

    /*
    |--------------------------------------------------------------------------
    | What the block will and will not show
    |--------------------------------------------------------------------------
    */

    public function testMostLikedIsOrderedByApproval(): void
    {
        $this->votes()->record(self::SENTINEL . '-a', self::VOTER_A, true);
        $this->votes()->record(self::SENTINEL . '-b', self::VOTER_A, true);
        $this->votes()->record(self::SENTINEL . '-b', self::VOTER_B, true);

        $liked = array_keys(array_filter(
            $this->votes()->liked(10),
            static fn(string $slug): bool => str_starts_with($slug, self::SENTINEL),
            ARRAY_FILTER_USE_KEY,
        ));

        self::assertSame([self::SENTINEL . '-b', self::SENTINEL . '-a'], $liked);
    }

    /**
     * A post everybody disliked is not a liked post.
     *
     * Without the `HAVING`, a row of nothing but thumbs-down would sort above a
     * post with no votes at all and appear under a heading saying readers
     * liked it.
     */
    public function testAPostWithOnlyThumbsDownIsNotLiked(): void
    {
        $this->votes()->record(self::SENTINEL . '-disliked', self::VOTER_A, false);
        $this->votes()->record(self::SENTINEL . '-disliked', self::VOTER_B, false);

        self::assertArrayNotHasKey(self::SENTINEL . '-disliked', $this->votes()->liked(10));
    }

    /**
     * And with nothing voted on, there is nothing to show.
     *
     * This is the whole reason the block is conditional: an untouched install
     * has no votes, so "most liked" would otherwise be whatever the tiebreaker
     * happened to put first.
     */
    public function testNothingVotedOnMeansNothingLiked(): void
    {
        $mine = array_filter(
            $this->votes()->liked(10),
            static fn(string $slug): bool => str_starts_with($slug, self::SENTINEL),
            ARRAY_FILTER_USE_KEY,
        );

        self::assertSame([], $mine);
    }

    public function testDeletingAPostForgetsWhatReadersSaidAboutIt(): void
    {
        $slug = self::SENTINEL . '-gone';

        $this->votes()->record($slug, self::VOTER_A, true);
        $this->votes()->delete($slug);

        self::assertSame(['votes' => 0, 'helpful' => 0], $this->votes()->tallyFor($slug));
    }
}
