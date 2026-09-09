<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\View;

use TripBuilder\ArticleRating;
use TripBuilder\Config;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Repository\ArticleVoteRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\View\LayoutData;

/**
 * The footer's help column, ordered by what readers said.
 *
 * An integration test because the ranking is the only half worth testing and
 * it does not exist without rows: with no votes every article scores nought
 * and the column is simply the order config wrote it in, which the unit suite
 * already covers. The interesting case needs a table.
 *
 * Real slugs, because topRatedHelp() starts from the article index and a
 * sentinel slug would be filtered straight back out -- so unlike
 * CurrencyRateRepositoryTest there is nowhere out of the way to put these. The
 * voters are sentinels instead, and `finally` removes them however the
 * assertions go. While the suite runs, a page rendered in another window would
 * see this ranking; nothing is left behind after it.
 */
final class TopRatedHelpTest extends IntegrationTestCase
{
    /** Every voter this test invents starts with this. */
    private const string VOTER_PREFIX = 'ratingtest';

    protected function setUp(): void
    {
        new Config('common');
    }

    protected function tearDown(): void
    {
        $this->connection()->execute(
            'DELETE FROM article_votes WHERE voter LIKE ?',
            [self::VOTER_PREFIX . '%'],
        );
    }

    /**
     * A perfect record over three votes does not outrank a strong one over
     * eighteen.
     *
     * The whole reason the column is not ordered by the share who said yes.
     * Both figures are asserted first, so a miscount cannot be mistaken for a
     * ranking decision.
     */
    public function testEvidenceOutranksAPerfectRecord(): void
    {
        $this->castVotes('baggage', 18, 16);
        $this->castVotes('ticket-not-received', 3, 3);

        $order = array_values(new LayoutData()->topRatedHelp(5));

        self::assertSame(
            '/help/baggage',
            $order[0],
            'sixteen of eighteen should lead three of three',
        );
        self::assertGreaterThan(
            array_search('/help/baggage', $order, true),
            array_search('/help/ticket-not-received', $order, true),
            'the three-vote article should sit below it',
        );
    }

    /**
     * And that ordering really does disagree with the share who said yes.
     *
     * Without this the test above would pass on an implementation that ranked
     * by percentage, since sixteen of eighteen could have won on either rule
     * for some other pair of figures.
     */
    public function testTheOrderingDisagreesWithTheBareShare(): void
    {
        $this->castVotes('baggage', 18, 16);
        $this->castVotes('ticket-not-received', 3, 3);

        self::assertGreaterThan(
            16 / 18,
            3 / 3,
            'sanity: the three-vote article really is the better percentage',
        );
        self::assertGreaterThan(
            ArticleRating::score(3, 3),
            ArticleRating::score(16, 18),
            'and the score really does reverse that',
        );
    }

    /**
     * An article nobody has voted on keeps its place in the column.
     *
     * At the bottom rather than missing: the set of articles comes from config
     * and the votes only order it, so a newly written article is linked from
     * the footer the day it exists.
     */
    public function testAnUnvotedArticleSinksButStaysListed(): void
    {
        $this->castVotes('refunds', 6, 6);

        $order = array_values(new LayoutData()->topRatedHelp(5));

        self::assertCount(count($this->repository()->all()), $order);
        self::assertSame('/help/refunds', $order[0], 'the only voted article should lead');
        self::assertContains('/help/baggage', $order, 'and an unvoted one is still offered');
    }

    /**
     * The labels are the short names whatever the order becomes.
     *
     * Reordering the column must not reach the two labels that were measured
     * for its width.
     */
    public function testTheShortLabelsSurviveReordering(): void
    {
        $this->castVotes('passenger-details', 8, 8);

        $links = new LayoutData()->topRatedHelp(5);

        self::assertArrayHasKey('Passenger details', $links);
        self::assertArrayNotHasKey('Changing passenger details', $links);
        self::assertSame('/help/passenger-details', $links['Passenger details']);
    }

    /**
     * With nobody having voted, the column is in `position` order.
     *
     * Moved here from the unit suite, where it had quietly become vacuous: the
     * catalogue it compared against was config, and when config went the
     * assertion was an empty array against an empty array. It needs rows to
     * mean anything, and this is the case a fresh install is actually in --
     * there is no seeder for article_votes, so `position` decides the whole
     * column until a reader clicks.
     */
    public function testWithNobodyVotingTheColumnIsInPositionOrder(): void
    {
        $expected = array_map(
            static fn(string $slug): string => '/help/' . $slug,
            array_keys($this->repository()->all()),
        );

        self::assertSame(
            $expected,
            array_values(new LayoutData()->topRatedHelp(5)),
            'position is the tiebreaker, and with no votes it is the whole order',
        );
    }

    /**
     * A disabled article leaves the column, along with everywhere else.
     *
     * The reason `enabled` is filtered in the repository rather than by each
     * caller: holding an article back has to hold it back from the footer, the
     * hub, the aside, the sitemap and the vote endpoint in one go, and a flag
     * each caller has to remember is a flag one of them will forget.
     */
    public function testADisabledArticleIsNotOffered(): void
    {
        $connection = $this->connection();
        $before = count(new LayoutData()->topRatedHelp(9));

        $connection->execute('UPDATE articles SET enabled = 0 WHERE slug = ?', ['refunds']);

        try {
            $links = new LayoutData()->topRatedHelp(9);

            self::assertCount($before - 1, $links, 'the disabled article should be gone');
            self::assertNotContains('/help/refunds', array_values($links));
        } finally {
            $connection->execute('UPDATE articles SET enabled = 1 WHERE slug = ?', ['refunds']);
        }

        self::assertContains(
            '/help/refunds',
            array_values(new LayoutData()->topRatedHelp(9)),
            'and back once it is enabled again',
        );
    }

    private function repository(): ArticleRepository
    {
        return new ArticleRepository($this->connection());
    }

    /** Cast `$votes` votes on one article, `$helpful` of them a yes. */
    private function castVotes(string $slug, int $votes, int $helpful): void
    {
        $repository = new ArticleVoteRepository($this->connection());

        for ($i = 1; $i <= $votes; $i++) {
            // Indexed rather than random, so every one of these is a distinct
            // voter -- the primary key would fold repeats into corrections and
            // the test would silently cast fewer votes than it claims.
            $repository->record($slug, sprintf('%s-%s-%03d', self::VOTER_PREFIX, substr($slug, 0, 8), $i), $i <= $helpful);
        }
    }
}
