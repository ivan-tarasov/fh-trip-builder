<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\Repository\PostRepository;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\Markdown;
use TripBuilder\View\TwigRenderer;

/**
 * Airside: the posts, and the page that lists them.
 *
 * Deliberately the thinner sibling of HelpController. Help has categories, a
 * rail, siblings and a rating, and every one of those is a join or a second
 * read. A post has a date and an author, so this is a list and a page.
 *
 * The guards are the same two, for the same reasons: the list failing means
 * there is nothing to show and the answer is a 404 rather than an error, and a
 * slug naming no post is also a 404 and not a failure.
 */
class AirsideController extends AbstractController
{
    /**
     * Every post, newest first.
     *
     * An empty section renders its own empty state rather than 404ing. A fresh
     * install has no posts -- there is no seeder for these tables, on purpose
     * -- and a page that exists and says so is a better answer than a page
     * that claims not to exist. The nav link is off until there is something
     * to read; see `site.main-menu`.
     */
    public function index(): void
    {
        try {
            $posts = new PostRepository($this->connection())->all();
        } catch (Throwable $e) {
            error_log('Airside index failed: ' . $e->getMessage());
            $this->notFound();

            return;
        }

        echo new TwigRenderer()->renderPage('airside/index.html.twig', [
            'posts' => $posts,
        ]);
    }

    public function show(): void
    {
        $slug = $this->slug();
        $canonical = mb_strtolower($slug);

        // One page, one address, as on help and the five place families. The
        // canonical link tag is built from the path that was asked for, so
        // /airside/Picking-A-Seat left alone would be a second URL declaring
        // itself the original.
        if ($slug !== $canonical) {
            $this->bounce('/airside/' . $canonical, 301);

            return;
        }

        try {
            $post = new PostRepository($this->connection())->find($slug);
        } catch (Throwable $e) {
            error_log('Airside page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this page. Please try again later.';

            return;
        }

        if ($post === null) {
            $this->notFound();

            return;
        }

        echo new TwigRenderer()->renderPage('airside/view.html.twig', [
            // Converted here and not in the template, the way help and /about
            // do it: a template handed markdown would have to know how to
            // render it, and the one thing this app never does is treat
            // stored text as template source.
            'post_html' => Markdown::toPostHtml($post['body']),
            'post' => $post + ['slug' => $slug],
            // Home / Airside / the post's name. Derived from the path the
            // trail would end in the slug, which is the address rather than
            // the title.
            'breadcrumbs' => Breadcrumbs::trail($this->request->path(), $post['title']),
        ]);
    }

    /**
     * Digits are allowed here and not in help's slugs.
     *
     * A help article is a subject -- "baggage", "refunds-and-exchanges" -- and
     * a post is a piece of writing, which can be "three-ways-to-pick-a-seat".
     * Kept in step with the route pattern in Routes::DYNAMIC_ROUTES and with
     * the check `airside:import` makes on a file name, because a slug no route
     * can match is a post nothing can reach.
     */
    private function slug(): string
    {
        return preg_match('#^/airside/([A-Za-z0-9-]+)$#', $this->request->path(), $match) === 1
            ? $match[1]
            : '';
    }
}
