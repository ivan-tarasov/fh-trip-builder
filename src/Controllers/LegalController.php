<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\Config;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\TwigRenderer;

/**
 * Privacy, Terms and Cookies.
 *
 * Three addresses and one method, because the three pages differ only in which
 * template holds their prose. Each is an exact route rather than a pattern:
 * there are three of them, they never grow from data, and spelling them out is
 * what puts them in the sitemap -- SitemapController reads ENABLED_ROUTES.
 *
 * Nothing here touches the database, so nothing here catches a database error.
 * The only failure worth a catch is a template that will not render, which is a
 * broken deploy rather than a missing page.
 */
class LegalController extends AbstractController
{
    public function show(): void
    {
        $slug = ltrim($this->request->path(), '/');
        $documents = self::documents();

        // Unreachable through the three routes that lead here, and cheap
        // insurance against a fourth being added without its template.
        if (!isset($documents[$slug])) {
            $this->notFound();

            return;
        }

        $document = $documents[$slug] + ['slug' => $slug];

        try {
            echo new TwigRenderer()->renderPage('legal/view.html.twig', [
                'breadcrumbs' => Breadcrumbs::trail($this->request->path(), (string) $document['title']),
                'document' => $document,
                // The other two. These pages are read in a set -- somebody who
                // wants to know about cookies has usually just read what is
                // stored -- and the footer row is otherwise the only way across.
                'more' => self::addressable(array_diff_key($documents, [$slug => null])),
            ]);
        } catch (Throwable $e) {
            error_log('Legal page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this page. Please try again later.';
        }
    }

    /**
     * The documents, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function documents(): array
    {
        /** @var array<string, array<string, mixed>> $documents */
        $documents = Config::get('legal.documents', []);

        return $documents;
    }

    /**
     * The documents as a list, each with the address it is reached at.
     *
     * @param array<string, array<string, mixed>> $documents
     * @return list<array<string, mixed>>
     */
    private static function addressable(array $documents): array
    {
        $links = [];

        foreach ($documents as $slug => $document) {
            $links[] = $document + ['slug' => $slug, 'url' => '/' . $slug];
        }

        return $links;
    }
}
