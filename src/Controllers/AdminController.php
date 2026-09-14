<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Admin;
use TripBuilder\Csrf;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\RateLimit;
use TripBuilder\Repository\ArticleCategoryRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\View\Markdown;
use TripBuilder\View\TwigRenderer;
use Twig\Error\Error;

/**
 * The way in, and the way out.
 *
 * One password, one session flag, no accounts table -- see `Admin` for why that
 * is the honest size of this rather than a corner cut (A3.2, #100).
 *
 * Everything under `/admin` goes through `guard()`, so a page added here is
 * gated by being here. That is deliberate: a panel where each action remembers
 * to check for itself is a panel where one of them eventually does not.
 */
class AdminController extends AbstractController
{
    /**
     * What a wrong password is told.
     *
     * One message for every way of being wrong, and it names neither the
     * password nor whether one is configured. There is a single account, so
     * "no such user" and "wrong password" would be the same sentence anyway --
     * but a panel that says "no password is set on this server" is telling a
     * stranger something worth knowing.
     */
    private const string REFUSED = 'That is not the password.';

    /** A slug is lower case, digits and hyphens, like every other one here. */
    private const string SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /**
     * The panel: every category, and the articles filed under each.
     *
     * Also takes the small POSTs the list itself makes -- move up, move down,
     * show, hide. They are forms rather than links because each one changes
     * something, and they land back here so a refresh does not repeat the last
     * one (A3.3, #101).
     *
     * @throws Exception|Error
     */
    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($this->request->isPost()) {
            $this->act();

            return;
        }

        $articles = new ArticleRepository($this->connection())->forPanel();
        $grouped = [];

        foreach ($articles as $article) {
            $grouped[$article['category']][] = $article;
        }

        echo new TwigRenderer()->renderPage('admin/index.html.twig', [
            'idle_minutes' => Admin::IDLE_MINUTES,
            'categories' => new ArticleCategoryRepository($this->connection())->forPanel(),
            'articles' => $grouped,
            // Articles whose category names no row. `all()` explains why an
            // orphan is kept rather than dropped; here it has to be visible,
            // because the panel is where it gets fixed.
            'orphans' => $this->orphans($grouped),
        ]);
    }

    /**
     * One article's form, and the saving of it.
     *
     * @throws Exception|Error
     */
    public function article(): void
    {
        if (!$this->guard()) {
            return;
        }

        $articles = new ArticleRepository($this->connection());
        $categories = new ArticleCategoryRepository($this->connection());
        $slug = $this->slugFromPath();

        if ($this->request->isPost()) {
            $this->saveArticle($articles, $categories, $slug);

            return;
        }

        // A slug in the path that names nothing is a link to an article
        // somebody has since deleted, not an invitation to create one under
        // that name -- so it is a 404 and not an empty form.
        $article = $slug === null ? null : $articles->forEditing($slug);

        if ($slug !== null && $article === null) {
            $this->notFound();

            return;
        }

        $this->articleForm($article, $categories);
    }

    /**
     * One category's form, and the saving of it.
     *
     * @throws Exception|Error
     */
    public function category(): void
    {
        if (!$this->guard()) {
            return;
        }

        $categories = new ArticleCategoryRepository($this->connection());
        $slug = $this->slugFromPath();

        if ($this->request->isPost()) {
            $this->saveCategory($categories, $slug);

            return;
        }

        $category = $slug === null ? null : $categories->forEditing($slug);

        if ($slug !== null && $category === null) {
            $this->notFound();

            return;
        }

        $this->categoryForm($category);
    }

    /**
     * Markdown in, HTML out, for the pane beside the editor.
     *
     * Rendered on the server by the same converter the help page uses, which
     * is the only way a preview is worth having: a second implementation in
     * the browser would agree with this one right up until it did not, and the
     * whole point of a preview is that it is what will be published.
     *
     * @throws Exception|Error
     */
    public function preview(): void
    {
        if (!Admin::isSignedIn()) {
            http_response_code(HttpStatus::Forbidden->value);

            return;
        }

        if (!$this->request->isPost() || !Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            http_response_code(HttpStatus::Forbidden->value);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        echo Markdown::toHtml($this->request->body->str('body'));
    }

    /**
     * The sign-in form, and the posting of it.
     *
     * @throws Exception|Error
     */
    public function login(): void
    {
        if (Admin::isSignedIn()) {
            $this->bounce('/admin');

            return;
        }

        if (!$this->request->isPost()) {
            $this->form();

            return;
        }

        // The token first, then the throttle. A 429 that arrived first would
        // answer a different question than it looks like -- whether the token
        // was accepted -- and would do it without spending one. The same order
        // AjaxController::guardFailure() settled on.
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->form(self::REFUSED, HttpStatus::Forbidden);

            return;
        }

        if ($this->isOverLimit(RateLimit::AdminLogin)) {
            $this->form(RateLimit::AdminLogin->refusal(), HttpStatus::TooManyRequests);

            return;
        }

        if (!Admin::verify($this->request->body->str('password'))) {
            $this->form(self::REFUSED, HttpStatus::Unauthorized);

            return;
        }

        Admin::signIn();
        $this->bounce('/admin');
    }

    /**
     * Sign out, on a POST.
     *
     * A link would do it too, and that is the reason it is a form: a GET that
     * changes something can be fired by any image tag on any page, and being
     * signed out by one is a small nuisance that says the panel is not careful.
     *
     * @throws Exception|Error
     */
    public function logout(): void
    {
        if ($this->request->isPost() && Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Admin::signOut();
        }

        $this->bounce('/admin/login');
    }

    /**
     * The list's own buttons: move, show, hide.
     *
     * One handler and one address, so the list has four small forms rather
     * than four routes. Everything ends in a redirect back to `/admin`, which
     * is what stops a refresh repeating the last move.
     */
    private function act(): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->bounce('/admin');

            return;
        }

        $slug = $this->request->body->str('slug');
        $kind = $this->request->body->str('kind');
        $action = $this->request->body->str('action');

        if (preg_match(self::SLUG, $slug) !== 1 || !in_array($kind, ['article', 'category'], true)) {
            $this->bounce('/admin');

            return;
        }

        $store = $kind === 'article'
            ? new ArticleRepository($this->connection())
            : new ArticleCategoryRepository($this->connection());

        $current = $kind === 'article'
            ? $store->forEditing($slug)
            : $store->forEditing($slug);

        if ($current !== null) {
            match ($action) {
                'show' => $store->setEnabled($slug, true),
                'hide' => $store->setEnabled($slug, false),
                // A swap with the neighbour rather than a step. Positions are
                // spaced 10, 20, 30 in the seeded data, so adding one to them
                // moved nothing and the button looked broken.
                'up' => $store->move($slug, -1),
                'down' => $store->move($slug, 1),
                default => null,
            };
        }

        $this->bounce('/admin');
    }

    /**
     * Save one article, or draw the form again saying what is wrong.
     */
    private function saveArticle(
        ArticleRepository $articles,
        ArticleCategoryRepository $categories,
        ?string $slug,
    ): void {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->articleForm($this->postedArticle($slug), $categories, 'That form went stale. Try again.');

            return;
        }

        $posted = $this->postedArticle($slug);
        $known = $categories->slugs();

        $error = match (true) {
            preg_match(self::SLUG, $posted['slug']) !== 1
                => 'A slug is lower case letters, digits and hyphens.',
            // Checked here and not only by the select, because the form is one
            // way to reach this and not the only one.
            !in_array($posted['category'], $known, true)
                => 'That category does not exist.',
            trim($posted['title']) === '' => 'An article needs a title.',
            trim($posted['summary']) === '' => 'An article needs a summary.',
            // A new slug that is already taken would overwrite somebody else's
            // article through the upsert rather than failing.
            $slug === null && $articles->forEditing($posted['slug']) !== null
                => 'There is already an article with that slug.',
            default => null,
        };

        if ($error !== null) {
            $this->articleForm($posted, $categories, $error);

            return;
        }

        $articles->store(
            $posted['slug'],
            [
                'category' => $posted['category'],
                'icon' => $posted['icon'],
                'position' => $posted['position'],
            ],
            [
                'title' => $posted['title'],
                // An empty short name is no short name: the column is nullable
                // and the readers fall back to the title.
                'short' => $posted['short'] === '' ? null : $posted['short'],
                'summary' => $posted['summary'],
                'body' => $posted['body'],
            ],
        );

        // After the write, because `store()` inserts a new row enabled and
        // leaves an existing row's flag alone -- deliberately, so an import
        // cannot re-enable something held back. The panel is where that
        // decision is made, so it makes it here.
        $articles->setEnabled($posted['slug'], $posted['enabled']);

        $this->bounce('/admin');
    }

    /**
     * Save one category, or draw the form again saying what is wrong.
     */
    private function saveCategory(ArticleCategoryRepository $categories, ?string $slug): void
    {
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->categoryForm($this->postedCategory($slug), 'That form went stale. Try again.');

            return;
        }

        $posted = $this->postedCategory($slug);

        $error = match (true) {
            preg_match(self::SLUG, $posted['slug']) !== 1
                => 'A slug is lower case letters, digits and hyphens.',
            trim($posted['title']) === '' => 'A category needs a title.',
            trim($posted['summary']) === '' => 'A category needs a summary.',
            $slug === null && $categories->forEditing($posted['slug']) !== null
                => 'There is already a category with that slug.',
            default => null,
        };

        if ($error !== null) {
            $this->categoryForm($posted, $error);

            return;
        }

        $categories->store(
            $posted['slug'],
            [
                'icon' => $posted['icon'],
                'accent' => $posted['accent'],
                'position' => $posted['position'],
            ],
            ['title' => $posted['title'], 'summary' => $posted['summary']],
        );

        $categories->setEnabled($posted['slug'], $posted['enabled']);

        $this->bounce('/admin');
    }

    /**
     * What was typed, shaped like what was loaded.
     *
     * So a form that is refused comes back holding the words rather than
     * emptied -- losing a paragraph to a mistyped slug is how an editor earns
     * a reputation.
     *
     * @return array{slug: string, title: string, short: ?string, icon: string, category: string, summary: string, body: string, position: int, enabled: bool}
     */
    private function postedArticle(?string $slug): array
    {
        $body = $this->request->body;

        return [
            // An existing article keeps the slug in its address: renaming one
            // would orphan every link to it, and there is nothing here that
            // would move them.
            'slug' => $slug ?? trim($body->str('slug')),
            'title' => trim($body->str('title')),
            'short' => trim($body->str('short')),
            'icon' => trim($body->str('icon')),
            'category' => trim($body->str('category')),
            'summary' => trim($body->str('summary')),
            'body' => $body->str('body'),
            'position' => (int) $body->str('position'),
            'enabled' => $body->str('enabled') !== '',
        ];
    }

    /**
     * @return array{slug: string, title: string, summary: string, icon: string, accent: string, position: int, enabled: bool}
     */
    private function postedCategory(?string $slug): array
    {
        $body = $this->request->body;

        return [
            'slug' => $slug ?? trim($body->str('slug')),
            'title' => trim($body->str('title')),
            'summary' => trim($body->str('summary')),
            'icon' => trim($body->str('icon')),
            'accent' => trim($body->str('accent')),
            'position' => (int) $body->str('position'),
            'enabled' => $body->str('enabled') !== '',
        ];
    }

    /**
     * @param array<string, mixed>|null $article
     * @throws Exception|Error
     */
    private function articleForm(?array $article, ArticleCategoryRepository $categories, ?string $error = null): void
    {
        echo new TwigRenderer()->renderPage('admin/article.html.twig', [
            'article' => $article,
            'categories' => $categories->forPanel(),
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, mixed>|null $category
     * @throws Exception|Error
     */
    private function categoryForm(?array $category, ?string $error = null): void
    {
        echo new TwigRenderer()->renderPage('admin/category.html.twig', [
            'category' => $category,
            'accent' => ArticleCategoryRepository::DEFAULT_ACCENT,
            'error' => $error,
        ]);
    }

    /**
     * The slug in the address, or null where there is none and this is a new
     * one being written.
     */
    private function slugFromPath(): ?string
    {
        $parts = explode('/', trim($this->request->path(), '/'));
        $last = end($parts);

        // `/admin/article` has two segments and names no slug; three is an
        // edit. The pattern in Routes guarantees the shape, so this only has
        // to say which of the two it is.
        return count($parts) > 2 && preg_match(self::SLUG, $last) === 1 ? $last : null;
    }

    /**
     * Articles filed under a category that does not exist.
     *
     * @param array<string, list<array<string, mixed>>> $grouped
     * @return list<array<string, mixed>>
     */
    private function orphans(array $grouped): array
    {
        $known = new ArticleCategoryRepository($this->connection())->slugs();
        $orphans = [];

        foreach ($grouped as $category => $articles) {
            if (!in_array((string) $category, $known, true)) {
                $orphans = [...$orphans, ...$articles];
            }
        }

        return $orphans;
    }

    /**
     * Let a signed-in operator through, or send them to the form.
     *
     * Returns false having already answered, so a caller is one `if` away from
     * being gated.
     */
    protected function guard(): bool
    {
        if (Admin::isSignedIn()) {
            return true;
        }

        $this->bounce('/admin/login');

        return false;
    }

    /**
     * @throws Exception|Error
     */
    private function form(?string $error = null, HttpStatus $status = HttpStatus::Ok): void
    {
        if ($status !== HttpStatus::Ok && !headers_sent()) {
            http_response_code($status->value);
        }

        echo new TwigRenderer()->renderPage('admin/login.html.twig', [
            'error' => $error,
            // So a server with no hash says so on its own sign-in page, where
            // the person who can fix it is standing, rather than refusing a
            // correct password with no explanation.
            'configured' => Admin::isConfigured(),
        ]);
    }
}
