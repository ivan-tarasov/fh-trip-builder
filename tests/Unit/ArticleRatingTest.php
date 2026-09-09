<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\ArticleRating;

/**
 * Ranking five articles by a handful of votes.
 *
 * The failure this file exists for is not a crash. Sorting by the share of
 * readers who said yes runs, returns a number for every article, and puts the
 * article with one vote at the top of the list -- so the bug ships looking
 * exactly like the feature. The ordering test below is therefore the important
 * one, and it is written as a disagreement between two orderings rather than as
 * a list of expected figures, because a list of figures would pass against any
 * formula somebody later pasted in.
 *
 * The figures are asserted as well, and they are taken from the score's
 * published definition rather than from a run of this code, so they can
 * disagree with it and fail.
 */
final class ArticleRatingTest extends TestCase
{
    /**
     * The article that would win on percentage and should not.
     *
     * One reader, who said yes. Nothing about it is suspicious except how
     * little we know, which is the entire problem.
     */
    private const array LONE_YES = [1, 1];

    /**
     * And the one that should win: a worse percentage, far more evidence.
     */
    private const array WELL_LIKED = [47, 50];

    /**
     * More votes at the same share must score higher.
     *
     * Both of these are 90%. If they scored the same, the function would be
     * measuring the percentage with extra steps and every case in this file
     * would still pass.
     */
    public function testEvidenceCountsAndNotJustTheShare(): void
    {
        $thin = ArticleRating::score(9, 10);
        $thick = ArticleRating::score(90, 100);

        self::assertGreaterThan($thin, $thick, 'a hundred votes should beat ten at the same 90%');
    }

    /**
     * The inversion, stated as an inversion.
     *
     * Percentage puts the single yes first. This score puts it last. If a
     * change ever makes these two agree, the score has stopped doing the one
     * thing it was chosen for.
     */
    public function testPercentageAndScoreDisagreeAboutTheLoneYes(): void
    {
        $articles = [self::LONE_YES, self::WELL_LIKED, [9, 10], [4, 5]];

        $byShare = $articles;
        usort($byShare, static fn(array $a, array $b): int => ($b[0] / $b[1]) <=> ($a[0] / $a[1]));

        $byScore = $articles;
        usort(
            $byScore,
            static fn(array $a, array $b): int => ArticleRating::score($b[0], $b[1]) <=> ArticleRating::score($a[0], $a[1]),
        );

        self::assertSame(self::LONE_YES, $byShare[0], 'sanity: percentage really does favour the lone yes');
        self::assertSame(self::WELL_LIKED, $byScore[0], 'the best-evidenced article should rank first');
        self::assertSame(self::LONE_YES, end($byScore), 'and one vote should rank last, not first');
    }

    /**
     * The published figures, to four places.
     *
     * @param array{int, int} $votes
     */
    #[DataProvider('knownScores')]
    public function testTheScoreMatchesItsDefinition(array $votes, float $expected): void
    {
        self::assertEqualsWithDelta($expected, ArticleRating::score($votes[0], $votes[1]), 0.0001);
    }

    /** @return iterable<string, array{array{int, int}, float}> */
    public static function knownScores(): iterable
    {
        yield '1 of 1' => [[1, 1], 0.2065];
        yield '5 of 5' => [[5, 5], 0.5655];
        yield '9 of 10' => [[9, 10], 0.5958];
        yield '47 of 50' => [[47, 50], 0.8378];
        yield '4 of 5' => [[4, 5], 0.3755];
        yield '3 of 4' => [[3, 4], 0.3006];
    }

    /**
     * Nobody has voted, so there is nothing to rank on.
     *
     * Zero and not a half: an article with no votes must not sort above one
     * that has been read and disliked, and on a freshly installed database
     * every article is this case at once.
     */
    public function testAnArticleWithNoVotesScoresNothing(): void
    {
        self::assertSame(0.0, ArticleRating::score(0, 0));
        self::assertSame(0.0, ArticleRating::score(4, 0), 'yeses without votes are not votes');
    }

    /**
     * Disliked by everyone who voted is nought, at every number of votes.
     *
     * Every one of them, not a sample: the two halves of the interval are
     * mathematically equal here and equal only to within a rounding error, so
     * nought out of fifteen came out at -2.2e-17 before the result was held
     * inside its range. A negative score sorts *below* an article nobody has
     * voted on, which is a flat nought -- so the article somebody actually
     * read and disliked would have ranked last of all.
     */
    public function testAUnanimousNoScoresNothingAtEveryNumberOfVotes(): void
    {
        for ($votes = 1; $votes <= 60; $votes++) {
            self::assertSame(0.0, ArticleRating::score(0, $votes), "0 of $votes was not exactly nought");
        }
    }

    /**
     * Nonsense in does not produce a score that sorts unpredictably.
     *
     * More yeses than votes makes the square root's argument negative and the
     * result NaN, and NaN neither fails nor sorts -- it quietly scatters the
     * column it is ordering. SQL cannot hand us that pair, which is exactly why
     * it is asserted rather than trusted.
     *
     * @param array{int, int} $votes
     */
    #[DataProvider('impossibleTallies')]
    public function testAnImpossibleTallyStillReturnsAUsableNumber(array $votes): void
    {
        $score = ArticleRating::score($votes[0], $votes[1]);

        self::assertFalse(is_nan($score), 'a NaN score would scatter the ordering silently');
        self::assertGreaterThanOrEqual(0.0, $score);
        self::assertLessThanOrEqual(1.0, $score);
    }

    /** @return iterable<string, array{array{int, int}}> */
    public static function impossibleTallies(): iterable
    {
        yield 'more yeses than votes' => [[99, 10]];
        yield 'negative yeses' => [[-5, 10]];
        yield 'negative votes' => [[1, -1]];
        yield 'both negative' => [[-1, -1]];
    }

    /**
     * And the score stays inside 0..1 for every tally, not just the tidy ones.
     *
     * A sign slip in the interval arithmetic gives figures above 1 or below 0
     * that still sort in roughly the right order, so the ranking tests above
     * would not notice.
     */
    public function testEveryScoreIsAShareAndNotSomethingElse(): void
    {
        for ($votes = 1; $votes <= 60; $votes++) {
            for ($helpful = 0; $helpful <= $votes; $helpful++) {
                $score = ArticleRating::score($helpful, $votes);

                self::assertGreaterThanOrEqual(0.0, $score, "$helpful of $votes fell below nought");
                self::assertLessThanOrEqual(1.0, $score, "$helpful of $votes rose above one");
            }
        }
    }

    /**
     * More yeses is always better, at a fixed number of votes.
     */
    public function testTheScoreRisesWithEveryExtraYes(): void
    {
        for ($votes = 1; $votes <= 30; $votes++) {
            for ($helpful = 1; $helpful <= $votes; $helpful++) {
                self::assertGreaterThan(
                    ArticleRating::score($helpful - 1, $votes),
                    ArticleRating::score($helpful, $votes),
                    "$helpful of $votes did not beat " . ($helpful - 1) . " of $votes",
                );
            }
        }
    }

    /**
     * Where the tally starts being shown to a reader.
     *
     * The boundary is asserted from both sides because an off-by-one here is
     * the difference between "100% helpful (1 vote)" reaching a page and not.
     */
    public function testATallyIsOnlyShownOnceThereIsEnoughOfIt(): void
    {
        self::assertFalse(ArticleRating::worthShowing(0));
        self::assertFalse(ArticleRating::worthShowing(ArticleRating::SHOW_FROM - 1));
        self::assertTrue(ArticleRating::worthShowing(ArticleRating::SHOW_FROM));
        self::assertTrue(ArticleRating::worthShowing(ArticleRating::SHOW_FROM + 1));
    }
}
