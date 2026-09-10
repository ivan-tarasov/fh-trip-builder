<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use RuntimeException;
use Throwable;
use TripBuilder\ArticleRating;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Repository\ArticleVoteRepository;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\Markdown;
use TripBuilder\View\TwigRenderer;
use TripBuilder\Voter;

/**
 * The help articles, and the page that lists them.
 *
 * This used to be the only family here that was not rows in a table -- a
 * lookup in config/common/help.php and a template. The articles are rows now,
 * for reasons an array could not serve: a translation is a row keyed by
 * language, an editor needs somewhere to write, and "last updated" is a fact a
 * config file cannot state honestly.
 *
 * What is left in a file is the prose, one template per slug, which
 * help/view.html.twig still includes by name. So there are two catalogues
 * until that moves as well, and a row without a matching template renders the
 * catch block below rather than a page -- which is what
 * ArticleTemplatesTest exists to stop.
 *
 * Two queries can fail here and both are guarded rather than allowed to break
 * the page: the article list, without which there is nothing to show and the
 * answer is a 404, and the vote tally, where a rating block with no figures is
 * a smaller loss than no article. The third thing that can go wrong is a slug
 * naming no article, which is also a 404 and not an error.
 */
class HelpController extends AbstractController
{
    /**
     * The five topics, as a page of their own.
     *
     * Worth having for the reason /cities and /airports are: the footer column
     * reaches all five from every page, but a family with no page of its own
     * has nowhere to say what it covers, and nothing to link to from an
     * article's own "more help" list.
     */
    public function index(): void
    {
        echo new TwigRenderer()->renderPage('help/index.html.twig', [
            'articles' => self::addressable($this->articles()),
        ]);
    }

    public function show(): void
    {
        $slug = $this->slug();
        $articles = $this->articles();
        $canonical = mb_strtolower($slug);

        if (!isset($articles[$canonical])) {
            $this->notFound();

            return;
        }

        // One page, one address, as on the five place families. There is
        // nothing to look up here, so the only way to spell an article wrongly
        // and still reach it is in capitals -- and the canonical link tag is
        // built from the path that was asked for, so /help/Baggage left alone
        // would be a second URL declaring itself the original.
        if ($slug !== $canonical) {
            $this->bounce('/help/' . $canonical, 301);

            return;
        }

        $article = $articles[$slug] + ['slug' => $slug];

        try {
            // The prose, converted here rather than in the template, the way
            // AboutController converts the README: a template that was handed
            // markdown would have to know how to render it, and the one thing
            // this app never does is treat stored text as template source.
            $prose = $this->prose($slug);

            echo new TwigRenderer()->renderPage('help/view.html.twig', [
                'article_html' => $prose['html'],
                // The date the answer changed, which is the thing a reader
                // wants to know about a help page and the thing a template
                // could not tell them while the prose was a file: every
                // article's file carried the mtime of the last deploy that
                // touched the checkout. `articles:import` moves this only
                // where the prose it is loading differs from the prose
                // already stored, so it dates the words.
                'updated_at' => $prose['updated_at'],
                // Home / Help / Baggage. Derived from the path the trail would
                // end in the slug, which is the address rather than the name --
                // "ticket-not-received" where the page is called "Ticket did
                // not arrive".
                'breadcrumbs' => Breadcrumbs::trail(
                    $this->request->path(),
                    (string) $article['title'],
                ),
                'article' => $article,
                // The other four. An article is the one page on this site with
                // no data of its own to link out with, so its siblings are what
                // it offers -- and they are what stop each of these being
                // reachable only from the footer.
                'more' => self::addressable(array_diff_key($articles, [$slug => null])),
                'verdict' => $this->verdict($slug),
            ]);
        } catch (Throwable $e) {
            error_log('Help page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this page. Please try again later.';
        }
    }

    /**
     * The article's prose, converted from markdown, and the date it changed.
     *
     * Both off one row, because both come from the same read and the date is
     * only meaningful about the words it arrived with.
     *
     * Unguarded on purpose, unlike verdict(). A page with no rating figures is
     * still the page somebody came for; a page with no prose is not, so this
     * failing has to reach the catch in show() and answer honestly rather than
     * render an article that says nothing.
     *
     * @return array{html: string, updated_at: string}
     */
    private function prose(string $slug): array
    {
        $article = new ArticleRepository($this->connection())->find($slug);

        if ($article === null) {
            // all() said this slug exists, so find() disagreeing means the row
            // went between two queries in one request -- or the two methods
            // have drifted apart, which is worth hearing about loudly.
            throw new RuntimeException(sprintf('No prose stored for `%s`.', $slug));
        }

        return [
            'html' => Markdown::toHtml($article['body']),
            'updated_at' => $article['updated_at'],
        ];
    }

    /**
     * What the rating block needs: the tally, and how this reader voted.
     *
     * `mine` has three values and they are three different states. Null is a
     * reader who has not voted -- and, separately, a reader with no cookie at
     * all, which is everybody who has never voted on anything. False is a
     * reader who voted no, and must not be offered the buttons as though they
     * had not.
     *
     * `shown` is decided here rather than in the template so that this page
     * and the JSON the endpoint answers with cannot disagree about whether the
     * figures may be printed.
     *
     * @return array{slug: string, votes: int, yes: int, shown: bool, mine: bool|null}
     */
    private function verdict(string $slug): array
    {
        $tally = ['votes' => 0, 'helpful' => 0];
        $mine = null;

        try {
            $repository = new ArticleVoteRepository($this->connection());
            $tally = $repository->tallyFor($slug);
            $voter = Voter::current();

            if ($voter !== null) {
                $mine = $repository->verdictOf($slug, $voter);
            }
        } catch (Throwable) {
            // Nought votes and no verdict, which the block renders as its
            // opening state. A visitor who then votes gets a real answer or a
            // real error from the endpoint; what they do not get is a help
            // page that failed to load because a count did.
        }

        return [
            'slug' => $slug,
            'votes' => $tally['votes'],
            'yes' => $tally['helpful'],
            'shown' => ArticleRating::worthShowing($tally['votes']),
            'mine' => $mine,
        ];
    }

    /**
     * The article index, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    private function articles(): array
    {
        try {
            return new ArticleRepository($this->connection())->all();
        } catch (Throwable $e) {
            // No list means no article and no hub, so this is not the kind of
            // failure the page can absorb the way it absorbs a missing vote
            // count. Logged and returned empty, which both callers read as
            // "no such article" and answer 404 -- an honest answer, where a
            // stack trace would not be.
            error_log('Help articles unavailable: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The articles as a list, each with the address it is reached at.
     *
     * The slug is the address here, unlike every other family on the site, so
     * this is the one place that writes "/help/" in front of one.
     *
     * @param array<string, array<string, mixed>> $articles
     * @return list<array<string, mixed>>
     */
    private static function addressable(array $articles): array
    {
        $links = [];

        foreach ($articles as $slug => $article) {
            $links[] = $article + ['slug' => $slug, 'url' => '/help/' . $slug];
        }

        return $links;
    }

    /**
     * The slug out of /help/<slug>, as written.
     *
     * As written and not folded, for the reason the city page gives: the
     * lookup lower-cases it, but the canonical check has to see the capitals to
     * have something to send somewhere.
     */
    private function slug(): string
    {
        return preg_match('#^/help/([A-Za-z-]+)$#', $this->request->path(), $match) === 1
            ? $match[1]
            : '';
    }
}
