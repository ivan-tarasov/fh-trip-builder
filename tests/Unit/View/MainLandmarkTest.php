<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every page opens exactly one main landmark.
 *
 * Three did not, and they were the busiest in the app: the homepage, the
 * search results and the 404. The skip link added in E3.1 closed bypass
 * blocks; the landmark is a different criterion, and its absence is the kind
 * of gap that is invisible from a browser -- a screen reader user navigating
 * by landmark simply finds nothing where the page should be.
 *
 * Asserted on the templates rather than on renders, for two reasons. Half of
 * these pages have no <main> of their own and reach one through
 * `partials/place.html.twig` or `partials/article.html.twig`, so the property
 * belongs to the template graph rather than to any one file. And rendering
 * every page type needs a database and a search, which is a lot of machinery
 * for a structural rule -- while a new page template shipping without a
 * landmark is exactly what this has to catch, and that is visible here.
 */
final class MainLandmarkTest extends TestCase
{
    /** How far to follow an include looking for the landmark. */
    private const int DEPTH = 2;

    public function testEveryPageOpensExactlyOneMainLandmark(): void
    {
        $counts = [];

        foreach ($this->pageTemplates() as $template) {
            $counts[$template] = $this->landmarksReachedBy($template, 0);
        }

        self::assertNotEmpty($counts, 'sanity: no page templates found to check');

        self::assertSame(
            array_fill_keys(array_keys($counts), 1),
            $counts,
            'a page reaching no <main> has no landmark; one reaching two has an ambiguous one',
        );
    }

    /**
     * How many `<main>` elements this template opens, following its includes.
     *
     * Twig comments are stripped first, and that is load bearing rather than
     * tidy: the notes added alongside these landmarks discuss `<main>` in
     * prose, and counting those made the 404 look as though it opened two.
     * The same trap as a `\bhero\b` guard matching `article--hero`, and as
     * UnitSuiteNeedsNoDatabaseTest matching the patterns it carries.
     */
    private function landmarksReachedBy(string $template, int $depth): int
    {
        $path = $this->root() . '/' . $template;

        // A dynamic include -- `'legal/documents/' ~ document.slug` -- captures
        // as a bare directory, so only real files are followed.
        if ($depth > self::DEPTH || !is_file($path)) {
            return 0;
        }

        $source = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));
        $own = preg_match_all('/<main\b/', $source);

        if ($own > 0) {
            return $own;
        }

        preg_match_all("/(?:include|embed)\s+'([^']+)'/", $source, $refs);

        foreach (array_unique($refs[1]) as $ref) {
            $found = $this->landmarksReachedBy($ref, $depth + 1);

            if ($found > 0) {
                return $found;
            }
        }

        return 0;
    }

    /**
     * Every template that is a page: the ones extending the layout.
     *
     * A partial is not checked on its own -- `partials/article.html.twig` opens
     * a <main> and is not a page, and `partials/breadcrumbs.html.twig` opens
     * none and should not.
     *
     * @return list<string>
     */
    private function pageTemplates(): array
    {
        $found = [];
        $root = $this->root();

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), "extends 'layout")) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($found);

        return $found;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3) . '/frontend/template';
    }
}
