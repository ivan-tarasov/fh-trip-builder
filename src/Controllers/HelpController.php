<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\ArticleRating;
use TripBuilder\Config;
use TripBuilder\Repository\ArticleVoteRepository;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\TwigRenderer;
use TripBuilder\Voter;

/**
 * The help articles, and the page that lists them.
 *
 * The only family here that is not rows in a table. A city, an airline and a
 * route are all questions asked of the database; these five are answers
 * somebody wrote, and the whole controller is therefore a lookup in
 * config/common/help.php and a template.
 *
 * It used to be true that nothing in here caught a database error, because
 * there was no query to fail. An article now carries the votes readers have
 * given it, so there is one -- and it is guarded rather than allowed to break
 * the page, on the reasoning LayoutData's footer readers give: the prose is
 * what the visitor came for, and a rating block with no figures is a smaller
 * loss than no article. The other thing that can fail is a slug naming no
 * article, which is a 404 and not an error.
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
            'articles' => self::addressable(self::articles()),
        ]);
    }

    public function show(): void
    {
        $slug = $this->slug();
        $articles = self::articles();
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
            echo new TwigRenderer()->renderPage('help/view.html.twig', [
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
    private static function articles(): array
    {
        /** @var array<string, array<string, mixed>> $articles */
        $articles = Config::get('help.articles', []);

        return $articles;
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
