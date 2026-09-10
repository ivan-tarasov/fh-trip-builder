<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\ArticleRating;
use TripBuilder\Config;
use TripBuilder\Csrf;
use TripBuilder\Routes;
use TripBuilder\View\TwigRenderer;

/**
 * The help articles, and the hub that lists them.
 *
 * The only page family here that is prose rather than rows, which changes what
 * can go wrong with it. There is no query to fail; what fails is an article
 * added to the `articles` table with no template beside it, or a link in the
 * prose to a page that does not exist. Both would reach a reader as a working
 * page -- the first as "Something went wrong", the second as a 404 one click
 * away -- and neither would fail anything else in this suite.
 *
 * Every case is driven from the article index rather than from a list written
 * here, so a sixth article is covered by existing.
 */
final class HelpRenderTest extends TestCase
{
    /**
     * Stand-in prose, shaped like what View\Markdown emits.
     *
     * An `<h2>` first, because that is what the body must open with, and a
     * `<p>` after it. Nothing carries a class: article prose never has, and
     * `.article__body` styles it by descent.
     */
    private const string PROSE = '<h2>A heading</h2>' . "\n" . '<p>Some prose.</p>';

    protected function setUp(): void
    {
        new Config('common');

        // The footer reads a one-shot notice out of the session, as
        // FooterRenderTest explains.
        $_SESSION = [];
    }

    /**
     * A catalogue this test owns, naming the real slugs.
     *
     * Articles are rows now, and a data provider is static and runs before
     * setUp() does, so it cannot open a database connection. Rather than give
     * this suite one, the catalogue is a fixture: the *slugs* are real, because
     * the prose is still a template per slug and the point of several tests
     * below is that the template exists and renders; the titles and summaries
     * are invented, because no test here should fail when somebody edits a
     * sentence.
     *
     * What is no longer proved here is that the five real articles render.
     * That is ArticleCatalogueTest's job, with a database. Same split the vote
     * and rates work settled on, and the better shape regardless: a unit test
     * that renders a page should not also be asserting what is written on it.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function articles(): array
    {
        return [
            'baggage' => [
                'title' => 'Fixture: bags',
                'short' => null,
                'icon' => 'fa-suitcase-rolling',
                'summary' => 'A fixture sentence standing in for the baggage summary.',
            ],
            'refunds' => [
                'title' => 'Fixture: refunds and exchanges',
                'short' => 'Fixture: refunds',
                'icon' => 'fa-rotate-left',
                'summary' => 'A fixture sentence standing in for the refunds summary.',
            ],
            'ticket-not-received' => [
                'title' => 'Fixture: ticket did not arrive',
                'short' => null,
                'icon' => 'fa-envelope',
                'summary' => 'A fixture sentence standing in for the ticket summary.',
            ],
            'passenger-details' => [
                'title' => 'Fixture: changing passenger details',
                'short' => 'Fixture: passenger details',
                'icon' => 'fa-passport',
                'summary' => 'A fixture sentence standing in for the passenger summary.',
            ],
            'flying-with-children' => [
                'title' => 'Fixture: flying with children',
                'short' => null,
                'icon' => 'fa-child',
                'summary' => 'A fixture sentence standing in for the children summary.',
            ],
        ];
    }

    /**
     * The whole page, with the three figures the footer needs.
     *
     * They normally arrive through renderPage()'s context merge, which
     * render() does not do.
     *
     * @param array<string, mixed> $context
     */
    private function render(string $template, string $page, array $context): string
    {
        Routes::setCurrentPage($page);

        return new TwigRenderer()->render($template, $context + [
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);
    }

    /**
     * Both verdicts are offered, in a form that works without a script.
     *
     * The hidden fields are the ones that only matter with scripting off: no
     * fetch means no header to carry the token in, and no other way to say
     * which page to come back to. Named after the constants rather than
     * spelled out, which is the lesson FooterRenderTest records about the same
     * two fields on the subscribe form.
     */
    public function testTheRatingBlockOffersBothVerdictsInARealForm(): void
    {
        $html = $this->article('baggage');

        self::assertStringContainsString('action="/ajax/article-vote"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('name="helpful" value="1"', $html);
        self::assertStringContainsString('name="helpful" value="0"', $html);
        self::assertStringContainsString('name="slug" value="baggage"', $html);
        self::assertStringContainsString('name="' . Csrf::FIELD . '" value="', $html);
        self::assertStringContainsString('name="return_to" value="/help/baggage"', $html);
    }

    /**
     * And it posts somewhere that exists.
     *
     * A form action is a URL like any other, and this app's own footer test was
     * written about links to pages that were never built.
     */
    public function testTheVoteFormPostsToARouteThatExists(): void
    {
        self::assertSame('Ajax@articleVote', Routes::resolve('/ajax/article-vote'));
    }

    /**
     * A fresh article states nothing about its readers.
     *
     * The state every article is really in, because article_votes has no
     * seeder. No figures, no marked button, and an empty status line -- which
     * CSS hides with `:empty` rather than leaving a gap.
     */
    public function testAnArticleNobodyHasVotedOnShowsNoFigures(): void
    {
        $html = $this->article('baggage');

        self::assertStringNotContainsString('readers found this helpful</p>', $html);
        self::assertStringNotContainsString('article__verdict-button--chosen', $html);
        self::assertMatchesRegularExpression('/js-vote-tally[^>]*\shidden/', $html, 'the tally should be hidden');
        self::assertMatchesRegularExpression(
            '/js-vote-status[^>]*>\s*<\/p>/',
            $html,
            'the status line should render empty so :empty can collapse it',
        );
    }

    /**
     * This reader is told what they already said, and which button said it.
     *
     * A thumbs-down is a vote. Rendering it the same as never having voted
     * would offer the buttons again to somebody who has used them, and the
     * verdict they gave would be invisible to them while sitting on the table.
     */
    public function testTheReadersOwnVerdictIsShownBackToThem(): void
    {
        $yes = $this->article('baggage', self::verdict('baggage', 9, 8, true));

        self::assertStringContainsString('You said this sorted it.', $yes);
        self::assertMatchesRegularExpression(
            '/js-vote-yes article__verdict-button--chosen/',
            $yes,
            'the thumbs-up should be the marked one',
        );

        $no = $this->article('baggage', self::verdict('baggage', 9, 8, false));

        self::assertStringContainsString('You said you were still stuck.', $no);
        self::assertMatchesRegularExpression('/js-vote-no article__verdict-button--chosen/', $no);
        self::assertStringNotContainsString('js-vote-yes article__verdict-button--chosen', $no);
        self::assertSame(
            1,
            substr_count($no, 'article__verdict-button--chosen'),
            'exactly one button is the chosen one',
        );
    }

    /**
     * Each thumb says which way it points, in a class the stylesheet can see.
     *
     * The green and the red hang off `--up` and `--down`, not off the `js-`
     * hooks next to them -- nothing else in main.css styles a js- class, since
     * those name behaviour and a script is free to move them. So a missing
     * modifier costs the colour and nothing else, which is exactly the sort of
     * loss nobody notices in review.
     */
    public function testEachThumbCarriesTheDirectionItsColourHangsOn(): void
    {
        $html = $this->article('baggage');

        self::assertMatchesRegularExpression(
            '/article__verdict-button--up[^"]*js-vote-yes/',
            $html,
            'the thumbs-up should carry --up',
        );
        self::assertMatchesRegularExpression(
            '/article__verdict-button--down[^"]*js-vote-no/',
            $html,
            'the thumbs-down should carry --down',
        );
        self::assertSame(1, substr_count($html, 'article__verdict-button--up'), 'one up');
        self::assertSame(1, substr_count($html, 'article__verdict-button--down'), 'one down');
    }

    /**
     * Figures appear only once there are enough of them to be figures.
     *
     * The boundary is read from ArticleRating rather than written here, so the
     * page and the endpoint cannot end up disagreeing about it -- they both ask
     * the same class.
     */
    public function testTheTallyAppearsOnlyOnceThereAreEnoughVotes(): void
    {
        $thin = $this->article('baggage', self::verdict('baggage', ArticleRating::SHOW_FROM - 1, 4));

        self::assertStringNotContainsString('readers found this helpful</p>', $thin);
        self::assertMatchesRegularExpression('/js-vote-tally[^>]*\shidden/', $thin);

        $enough = $this->article('baggage', self::verdict('baggage', ArticleRating::SHOW_FROM, 4));

        self::assertStringContainsString('4 of ' . ArticleRating::SHOW_FROM . ' readers found this helpful', $enough);
        self::assertDoesNotMatchRegularExpression('/js-vote-tally[^>]*\shidden/', $enough);
    }

    /**
     * The sentence has one spelling, and the script reuses it.
     *
     * Both halves of this block print that tally: PHP on load, JavaScript
     * after a vote. Written twice they would drift, which is the mistake the
     * cookie notice and the currency switcher avoid by taking their strings off
     * a data attribute -- so this asserts the rendered line really is that
     * attribute with the numbers put in, and not a second copy that happens to
     * match today.
     */
    public function testTheTallySentenceIsRenderedFromTheTemplateTheScriptUses(): void
    {
        $html = $this->article('baggage', self::verdict('baggage', 18, 16));

        self::assertSame(
            1,
            preg_match('/data-template="([^"]+)"/', $html, $match),
            'the tally should carry the pattern for the script',
        );

        $pattern = html_entity_decode($match[1]);

        self::assertStringContainsString('%yes%', $pattern);
        self::assertStringContainsString('%votes%', $pattern);
        self::assertStringContainsString(
            str_replace(['%yes%', '%votes%'], ['16', '18'], $pattern),
            $html,
            'the printed line should be that pattern, filled in',
        );
    }

    /**
     * A vote cast with no scripting is announced once, then forgotten.
     *
     * The no-script path posts, redirects, and leaves its answer in the
     * session, so the article has to say it on the way back. Reading without
     * clearing would repeat it on every article the visitor opened afterwards,
     * which is the bug FooterRenderTest records for the subscribe notice --
     * and both now read through the same one-shot helper.
     */
    public function testAVoteNoticeIsAnnouncedOnceAndThenCleared(): void
    {
        $_SESSION['article_vote_notice'] = ['tone' => 'good', 'message' => 'Thanks. Glad it helped.'];

        $first = $this->article('baggage');

        self::assertStringContainsString('Thanks. Glad it helped.', $first);
        self::assertStringContainsString('data-tone="good"', $first);
        self::assertArrayNotHasKey('article_vote_notice', $_SESSION, 'the notice should be consumed');

        self::assertStringNotContainsString(
            'Thanks. Glad it helped.',
            $this->article('baggage'),
            'and not said again on the next page',
        );
    }

    /**
     * Rubbish in the session is ignored rather than printed.
     */
    public function testAMalformedVoteNoticeIsIgnored(): void
    {
        $_SESSION['article_vote_notice'] = 'Thanks!';

        $html = $this->article('baggage');

        self::assertStringNotContainsString('Thanks!', $html);
        self::assertMatchesRegularExpression('/js-vote-status[^>]*>\s*<\/p>/', $html);
    }

    /**
     * One article page, built the way HelpController builds it.
     *
     * `$verdict` defaults to the state a fresh install is in -- no votes, no
     * cookie -- because there is no seeder for article_votes and that is
     * therefore what every article really looks like until somebody clicks.
     *
     * @param array<string, mixed>|null $verdict
     */
    private function article(string $slug, ?array $verdict = null): string
    {
        $articles = self::articles();

        return $this->render('help/view.html.twig', '/help/' . $slug, [
            'breadcrumbs' => [],
            'article' => $articles[$slug] + ['slug' => $slug],
            // Converted prose, which the controller now hands over ready to
            // print. Fixed markup rather than a real article's, because what
            // this suite tests is that the template places it -- what the five
            // published articles actually say is ArticleCatalogueTest's.
            'article_html' => self::PROSE,
            'siblings' => self::siblings($slug),
            'verdict' => $verdict ?? self::verdict($slug),
        ]);
    }

    /**
     * The shape HelpController::verdict() returns.
     *
     * @return array{slug: string, votes: int, yes: int, shown: bool, mine: bool|null}
     */
    private static function verdict(
        string $slug,
        int $votes = 0,
        int $yes = 0,
        ?bool $mine = null,
    ): array {
        return [
            'slug' => $slug,
            'votes' => $votes,
            'yes' => $yes,
            // Taken from the same class the controller asks, not from a number
            // written here, so the threshold has one definition.
            'shown' => ArticleRating::worthShowing($votes),
            'mine' => $mine,
        ];
    }

    /**
     * One case per article in the fixture catalogue.
     *
     * @return list<array{string}>
     */
    public static function articleProvider(): array
    {
        return array_map(static fn(string $slug): array => [$slug], array_keys(self::articles()));
    }

    /**
     * The converted prose is placed in the body, and placed unescaped.
     *
     * A stand-in body rather than a real one, and one article rather than all
     * five: what this can prove is that the template prints what the
     * controller hands it, in the element the stylesheet dresses by descent.
     * Whether the five published articles have prose worth reading -- or any
     * prose at all -- is a question about rows, and ArticleCatalogueTest asks
     * it against the table.
     *
     * The heading is matched as markup rather than as text because that is the
     * half that can regress quietly: drop the `|raw` and the page fills with
     * `&lt;h2&gt;`, which is still a body with a heading in it as far as a
     * looser assertion is concerned.
     */
    public function testTheConvertedProseIsPlacedInTheBody(): void
    {
        $html = $this->article('baggage');

        self::assertMatchesRegularExpression(
            '#<div class="article__body">\s*<h2>A heading</h2>#s',
            $html,
            'the body should open with the prose it was handed',
        );
        self::assertStringContainsString('<p>Some prose.</p>', $html);
        self::assertStringNotContainsString('&lt;h2&gt;', $html, 'the prose has been escaped');
    }

    /**
     * One sentence in one place: the summary is the description and the lead.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('articleProvider')]
    public function testTheSummaryIsBothTheDescriptionAndTheLead(string $slug): void
    {
        $html = $this->article($slug);
        $summary = (string) self::articles()[$slug]['summary'];

        preg_match('#<p class="article__lead">(.*?)</p>#s', $html, $lead);
        self::assertNotEmpty($lead, 'the lead should be findable');

        // Collapsed, because the template wraps the sentence across lines and
        // what is being asserted is the sentence rather than the indentation.
        self::assertSame(
            $summary,
            html_entity_decode(
                (string) preg_replace('/\s+/', ' ', trim($lead[1])),
                ENT_QUOTES | ENT_HTML5,
            ),
        );

        self::assertStringContainsString(
            'name="description" content="' . htmlspecialchars($summary, ENT_QUOTES),
            $html,
        );
    }

    /**
     * The rest of this article's group, and not this article.
     *
     * An article has no data of its own to link out with, so its siblings are
     * the whole of what it offers -- and an article listing itself is a link
     * back to the page it is on.
     *
     * The group and not the catalogue, which is what this used to assert.
     * Eight links under a finished article is a second hub; the two or three
     * about the same thing are a next step.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('articleProvider')]
    public function testTheAsideListsTheRestOfTheGroupAndNotThisArticle(string $slug): void
    {
        $html = $this->article($slug);
        $group = self::siblings($slug);

        // No closing quote in the pattern: the help page's aside carries a
        // modifier beside this class, and matching to the quote would find
        // only the pages that do not.
        preg_match('#<aside class="article__aside.*?</aside>#s', $html, $aside);
        self::assertNotEmpty($aside, 'the siblings list should be findable');

        self::assertStringContainsString(
            htmlspecialchars($group['title'], ENT_QUOTES),
            $aside[0],
            'the card should be titled with the group',
        );

        preg_match_all('#href="(/help/[^"]+)"#', $aside[0], $links);

        self::assertSame(
            array_column($group['articles'], 'slug'),
            array_map(static fn(string $href): string => substr($href, strlen('/help/')), $links[1]),
        );
        self::assertNotContains($slug, array_column($group['articles'], 'slug'));
    }

    /**
     * The card is the hub's card: the group's icon in the group's colour.
     *
     * The whole reason both pages share one partial. An icon here and a
     * different one there would be two designs for one thing, and the rows'
     * alignment is measured from this icon's width -- so a card that lost it
     * would indent its rows past nothing.
     */
    public function testTheAsideCarriesTheGroupsIconAndColour(): void
    {
        $html = $this->article('baggage');
        $group = self::siblings('baggage');

        preg_match('#<aside class="article__aside.*?</aside>#s', $html, $aside);

        self::assertStringContainsString('fas ' . $group['icon'], $aside[0], 'the group icon should be drawn');
        self::assertStringContainsString(
            'help-group__icon--' . $group['accent'],
            $aside[0],
            'and in the group colour the hub gives it',
        );
    }

    /**
     * But not the group's sentence, which the hub does print.
     *
     * The one difference between the two cards, and deliberate: a reader at
     * the foot of an article has just read the thing that sentence describes,
     * so repeating it there is a line spent saying nothing new.
     */
    public function testTheAsideLeavesOutTheGroupsSentence(): void
    {
        $html = $this->article('baggage');
        $group = self::siblings('baggage');

        self::assertNotSame('', $group['summary'], 'the fixture group should have a sentence to leave out');
        self::assertStringNotContainsString(htmlspecialchars($group['summary'], ENT_QUOTES), $html);
    }

    /**
     * And no card at all where the group has nobody else in it.
     *
     * A heading over an empty rule reads as a list that failed to load. The
     * controller answers null for an article alone in its group and for one
     * whose category row has gone, and this is the template's half of that.
     */
    public function testAnArticleWithNoSiblingsDrawsNoCard(): void
    {
        $html = $this->render('help/view.html.twig', '/help/baggage', [
            'breadcrumbs' => [],
            'article' => self::articles()['baggage'] + ['slug' => 'baggage'],
            'article_html' => self::PROSE,
            'siblings' => null,
            'verdict' => self::verdict('baggage'),
        ]);

        self::assertStringNotContainsString('article__aside', $html);
        self::assertStringContainsString('article__body', $html, 'the page itself should still render');
    }

    /**
     * The hub names all of them, with the line that tells them apart.
     */
    public function testTheHubListsEveryArticle(): void
    {
        $groups = self::helpGroups();
        $html = $this->render('help/index.html.twig', '/help', ['groups' => $groups]);

        foreach ($groups as $group) {
            // The group's own heading and sentence, and a rail link to it.
            self::assertStringContainsString('href="#' . $group['slug'] . '"', $html);
            self::assertStringContainsString(htmlspecialchars($group['title'], ENT_QUOTES), $html);
            self::assertStringContainsString(htmlspecialchars($group['summary'], ENT_QUOTES), $html);

            foreach ($group['articles'] as $article) {
                self::assertStringContainsString('href="' . $article['url'] . '"', $html);
                self::assertStringContainsString(
                    htmlspecialchars((string) $article['title'], ENT_QUOTES),
                    $html,
                );
            }
        }
    }

    /**
     * And it prints no per-article summary.
     *
     * The reversal worth writing down, because the page used to be built on
     * the opposite claim: five cards each carried a summary, on the reasoning
     * that the summary was the half telling somebody which page they wanted.
     * With nine articles in three groups the group's sentence does that, and
     * nine summaries in one column is a page nobody reads to the bottom of.
     *
     * Asserted rather than left to the template, because putting them back is
     * a one-line change that would look like an improvement in review.
     */
    public function testTheHubDoesNotRepeatEveryArticlesSummary(): void
    {
        $groups = self::helpGroups();
        $html = $this->render('help/index.html.twig', '/help', ['groups' => $groups]);

        foreach ($groups as $group) {
            foreach ($group['articles'] as $article) {
                self::assertStringNotContainsString(
                    htmlspecialchars((string) $article['summary'], ENT_QUOTES),
                    $html,
                    $article['slug'] . ' should be a row on the hub, not a card with a sentence',
                );
            }
        }
    }

    /**
     * The group one fixture article belongs to, minus that article.
     *
     * Derived from the same fixture groups the hub test uses, so the two
     * pages cannot disagree about which article is in which group.
     *
     * @return array{slug: string, title: string, summary: string, icon: string, accent: string, articles: list<array<string, mixed>>}
     */
    private static function siblings(string $slug): array
    {
        foreach (self::helpGroups() as $group) {
            $slugs = array_column($group['articles'], 'slug');

            if (!in_array($slug, $slugs, true)) {
                continue;
            }

            // The whole group row and not just its name: the card carries the
            // group's icon too, so the fixture has to be the shape the
            // controller hands over.
            return array_replace($group, [
                'articles' => array_values(array_filter(
                    $group['articles'],
                    static fn(array $article): bool => $article['slug'] !== $slug,
                )),
            ]);
        }

        self::fail($slug . ' is in none of the fixture groups');
    }

    /**
     * The fixture catalogue, arranged into groups the way HelpController does.
     *
     * Two groups rather than the three the site has, and uneven ones: a hub
     * that only ever renders equal groups would pass with a template that drew
     * the first article of each.
     *
     * @return list<array{slug: string, title: string, summary: string, icon: string, accent: string, articles: list<array<string, mixed>>}>
     */
    private static function helpGroups(): array
    {
        $articles = [];

        foreach (self::articles() as $slug => $article) {
            $articles[$slug] = $article + ['slug' => $slug, 'url' => '/help/' . $slug];
        }

        return [
            [
                'slug' => 'fixture-before',
                'title' => 'Fixture: before you book',
                'summary' => 'A fixture sentence standing in for the first group.',
                'icon' => 'fa-magnifying-glass',
                'accent' => 'blue',
                'articles' => [$articles['baggage'], $articles['flying-with-children']],
            ],
            [
                'slug' => 'fixture-after',
                'title' => 'Fixture: after you book',
                'summary' => 'A fixture sentence standing in for the second group.',
                'icon' => 'fa-credit-card',
                'accent' => 'green',
                'articles' => [
                    $articles['ticket-not-received'],
                    $articles['refunds'],
                    $articles['passenger-details'],
                ],
            ],
        ];
    }
}
