<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\ArticleRating;
use TripBuilder\Repository\ArticleVoteRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The composite key is the whole guard, and this is what makes that claim true.
 *
 * There is no rate limiting anywhere in this app and this feature adds none, so
 * the only thing standing between a thumbs-up button and an inflated count is
 * that `(slug, voter)` is the primary key. A second post from the same reader
 * has to correct the first row rather than add one, and a thumbs-down after a
 * thumbs-up has to leave one row reading down rather than one of each.
 *
 * A slug outside the catalogue is used throughout, on the reasoning
 * CurrencyRateRepositoryTest gives for `ZZT`: it fits the column, has no entry
 * on the `articles` table and so can never reach a page, which keeps these
 * rows out of what the site shows while the suite runs.
 *
 * Every row here is written by the test that reads it. There is no seeder for
 * this table -- see the note in its config for why -- so there is no fixture
 * data to lean on, and these assertions cannot be quietly invalidated by a
 * change to a committed CSV.
 */
final class ArticleVoteRepositoryTest extends IntegrationTestCase
{
    private const string TEST_SLUG = 'zzt-not-an-article';

    /**
     * Matched with LIKE, not equality: two tests below write to slugs suffixed
     * off the sentinel, and an exact delete leaves those rows on the table.
     */
    protected function tearDown(): void
    {
        // Nothing was written, so there is nothing to tidy -- and a skip
        // raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM article_votes WHERE slug LIKE ?', [self::TEST_SLUG . '%']);
    }

    /**
     * Voting twice is one vote, and the second one is the one that counts.
     *
     * The assertion this table's shape exists for. Without the key a reader
     * holding the button down decides which article the footer promotes.
     */
    public function testASecondVoteCorrectsTheFirstRatherThanAddingToIt(): void
    {
        $repository = $this->repository();
        $voter = $this->voter();

        $repository->record(self::TEST_SLUG, $voter, true);
        $repository->record(self::TEST_SLUG, $voter, true);

        self::assertSame(
            ['votes' => 1, 'helpful' => 1],
            $repository->tallyFor(self::TEST_SLUG),
            'the same reader voting yes twice is one yes',
        );

        $repository->record(self::TEST_SLUG, $voter, false);

        self::assertSame(
            ['votes' => 1, 'helpful' => 0],
            $repository->tallyFor(self::TEST_SLUG),
            'changing your mind should replace the vote, not cancel it out',
        );
    }

    /**
     * Different readers are different votes, which is the other half of the key.
     */
    public function testDifferentReadersEachGetAVote(): void
    {
        $repository = $this->repository();

        $repository->record(self::TEST_SLUG, $this->voter(), true);
        $repository->record(self::TEST_SLUG, $this->voter(), true);
        $repository->record(self::TEST_SLUG, $this->voter(), false);

        self::assertSame(['votes' => 3, 'helpful' => 2], $repository->tallyFor(self::TEST_SLUG));
    }

    /**
     * Three answers from verdictOf, not two.
     *
     * `null` is "has not voted" and `false` is "voted, and said no". Collapsing
     * them would offer the buttons again to somebody who has already used them,
     * which is both a worse page and a second vote.
     */
    public function testNotVotedAndVotedNoAreDifferentAnswers(): void
    {
        $repository = $this->repository();
        $yes = $this->voter();
        $no = $this->voter();
        $silent = $this->voter();

        $repository->record(self::TEST_SLUG, $yes, true);
        $repository->record(self::TEST_SLUG, $no, false);

        self::assertTrue($repository->verdictOf(self::TEST_SLUG, $yes));
        self::assertFalse($repository->verdictOf(self::TEST_SLUG, $no), 'a no is a vote');
        self::assertNull($repository->verdictOf(self::TEST_SLUG, $silent), 'and silence is not');
    }

    /**
     * A vote on one article is not a vote on another.
     */
    public function testAVoteIsScopedToItsArticle(): void
    {
        $repository = $this->repository();
        $voter = $this->voter();

        $repository->record(self::TEST_SLUG, $voter, true);

        self::assertNull($repository->verdictOf(self::TEST_SLUG . '-other', $voter));
        self::assertSame(['votes' => 0, 'helpful' => 0], $repository->tallyFor(self::TEST_SLUG . '-other'));
    }

    /**
     * An article nobody has voted on reads as nought, not as nothing.
     *
     * `COUNT(*)` with no `GROUP BY` always returns a row, and `SUM` over no
     * rows is NULL -- so this is the case where an uncast read would hand the
     * score a null and the page a blank where a nought belongs.
     *
     * `assertSame` is doing two jobs here and the second is the reason the
     * literals are written as integers: it compares strictly, so the `'0'` a
     * missed cast would produce fails this as surely as a wrong number would.
     */
    public function testAnArticleWithNoVotesReadsAsIntegerZeroes(): void
    {
        self::assertSame(['votes' => 0, 'helpful' => 0], $this->repository()->tallyFor(self::TEST_SLUG));
    }

    /**
     * The figures are integers, because the score they feed is typed.
     */
    public function testTheTallyIsIntegersAndNotStrings(): void
    {
        $repository = $this->repository();
        $repository->record(self::TEST_SLUG, $this->voter(), true);

        $tally = $repository->tally();

        self::assertArrayHasKey(self::TEST_SLUG, $tally);
        self::assertIsInt($tally[self::TEST_SLUG]['votes']);
        self::assertIsInt($tally[self::TEST_SLUG]['helpful']);
    }

    /**
     * Articles nobody has voted on are absent from the whole-table tally.
     *
     * Deliberate: this repository cannot know what the full set of articles is,
     * because that is config's job. Asserted so a caller is never written on
     * the assumption that every article has a key here.
     */
    public function testUnvotedArticlesAreAbsentRatherThanZero(): void
    {
        self::assertArrayNotHasKey(self::TEST_SLUG, $this->repository()->tally());
    }

    /**
     * Rows in, ordering out -- with the trap set on purpose.
     *
     * The unit tests prove the score prefers evidence; this proves the figures
     * reach it intact, through `SUM`/`COUNT` and PDO's stringly-typed results,
     * so the two halves cannot drift apart. The pair is chosen so a percentage
     * would get it wrong: three votes out of three is a perfect record and
     * would top the footer, while sixteen out of eighteen is merely very good.
     */
    public function testAPerfectRecordOverFewVotesDoesNotOutrankAStrongOneOverMany(): void
    {
        $strong = self::TEST_SLUG . '-strong';
        $perfect = self::TEST_SLUG . '-perfect';

        $this->castVotes($strong, 18, 16);
        $this->castVotes($perfect, 3, 3);

        $tally = $this->repository()->tally();

        self::assertSame(['votes' => 18, 'helpful' => 16], $tally[$strong]);
        self::assertSame(['votes' => 3, 'helpful' => 3], $tally[$perfect]);

        $strongScore = ArticleRating::score($tally[$strong]['helpful'], $tally[$strong]['votes']);
        $perfectScore = ArticleRating::score($tally[$perfect]['helpful'], $tally[$perfect]['votes']);

        self::assertGreaterThan(
            $tally[$strong]['helpful'] / $tally[$strong]['votes'],
            $tally[$perfect]['helpful'] / $tally[$perfect]['votes'],
            'sanity: the perfect record really does win on percentage',
        );
        self::assertGreaterThan($perfectScore, $strongScore, 'and must still lose on score');

        self::assertTrue(ArticleRating::worthShowing($tally[$strong]['votes']));
        self::assertFalse(
            ArticleRating::worthShowing($tally[$perfect]['votes']),
            'three votes is not a figure to show a reader',
        );
    }

    private function repository(): ArticleVoteRepository
    {
        return new ArticleVoteRepository($this->connection());
    }

    /** A voter token that cannot collide with a real cookie. */
    private function voter(): string
    {
        return 'test-' . uniqid();
    }

    /**
     * Cast `$votes` votes on one article, `$helpful` of them a yes.
     *
     * The index is in the token rather than trusting `uniqid()` to differ
     * inside a tight loop. It does here, but every one of these has to be a
     * distinct voter or the primary key turns the extras into corrections --
     * and a test that quietly casts fewer votes than it says is worse than one
     * that fails.
     */
    private function castVotes(string $slug, int $votes, int $helpful): void
    {
        $repository = $this->repository();
        $run = uniqid();

        for ($i = 1; $i <= $votes; $i++) {
            $repository->record($slug, sprintf('test-%s-%03d', $run, $i), $i <= $helpful);
        }
    }
}
