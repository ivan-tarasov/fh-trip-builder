<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Routes;
use TripBuilder\View\TwigRenderer;

/**
 * Mapbox GL is loaded on the pages that draw a map and nowhere else.
 *
 * It is 503KB of JavaScript, measured off the CDN, and five of the eighteen
 * page types draw a map. Loading it everywhere would put half a megabyte on the
 * checkout, and loading it nowhere would leave five pages with an empty box --
 * neither of which reports itself, which is why the condition has a test.
 *
 * The header and footer are rendered here as fragments, the way
 * FooterRenderTest does, because a whole page needs a database for the figures
 * in its footer. What that leaves uncovered is the layout wiring itself --
 * whether a page's `map` block reaches these two partials -- and that is
 * checked below by reading the templates rather than rendering them.
 */
final class MapAssetsTest extends TestCase
{
    /** Every page template that should ask for a map. */
    private const array MAP_PAGES = [
        'city/view.html.twig',
        'country/view.html.twig',
        'airport/view.html.twig',
        'airline/view.html.twig',
        'route/view.html.twig',
    ];

    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
        Routes::setCurrentPage('/airlines');
    }

    private function render(string $partial, bool $needsMap): string
    {
        return new TwigRenderer()->render($partial, [
            'needs_map' => $needsMap,
            'page_title' => 'Title',
            'page_description' => 'Description',
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);
    }

    public function testTheStylesheetIsLoadedOnlyWhereThereIsAMap(): void
    {
        self::assertStringContainsString(
            'mapbox-gl.css',
            $this->render('partials/header.html.twig', true),
        );

        self::assertStringNotContainsString(
            'mapbox-gl.css',
            $this->render('partials/header.html.twig', false),
            'half a megabyte of map should not reach a page without one',
        );
    }

    public function testTheScriptsAreLoadedOnlyWhereThereIsAMap(): void
    {
        $withMap = $this->render('partials/footer.html.twig', true);

        self::assertStringContainsString('mapbox-gl.js', $withMap);
        self::assertStringContainsString('/map.js', $withMap, 'and the code that drives it');

        $without = $this->render('partials/footer.html.twig', false);

        self::assertStringNotContainsString('mapbox-gl.js', $without);
        self::assertStringNotContainsString('/map.js', $without);
    }

    /**
     * The library version is pinned in one place, and both tags use it.
     *
     * Two tags fetching two different versions of a WebGL library is a class of
     * bug that shows up as an unreadable console error, so they are asserted to
     * agree.
     */
    public function testBothTagsAskForThePinnedVersion(): void
    {
        $version = (string) Config::get('maps.gl_version');

        self::assertMatchesRegularExpression('/^v\d+\.\d+\.\d+$/', $version, 'pinned, not floating');

        self::assertStringContainsString(
            '/mapbox-gl-js/' . $version . '/mapbox-gl.css',
            $this->render('partials/header.html.twig', true),
        );
        self::assertStringContainsString(
            '/mapbox-gl-js/' . $version . '/mapbox-gl.js',
            $this->render('partials/footer.html.twig', true),
        );
    }

    /**
     * A page that draws a map declares it, and the marker never reaches the
     * document.
     *
     * Read from the templates, because this is the half the fragment renders
     * above cannot see: the block is what turns `needs_map` on, and a page that
     * adds a map without it gets an empty box with no error anywhere.
     */
    public function testEveryPageWithAMapDeclaresIt(): void
    {
        foreach (self::MAP_PAGES as $page) {
            $source = (string) file_get_contents(
                Helper::getRootDir() . '/frontend/template/' . $page,
            );

            self::assertStringContainsString(
                '{% block map %}',
                $source,
                $page . ' draws a map, so it has to declare one',
            );
        }
    }

    /**
     * And a page that declares one is a page that draws one.
     *
     * The other direction of the same rule: a leftover declaration is half a
     * megabyte of JavaScript on a page with nothing to show with it.
     */
    public function testNoOtherPageDeclaresAMap(): void
    {
        $root = Helper::getRootDir() . '/frontend/template';
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // The layout declares the empty block every page inherits.
            if (str_contains($source, '{% block map %}') && $file->getFilename() !== 'layout.html.twig') {
                $found[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($found);
        $expected = self::MAP_PAGES;
        sort($expected);

        self::assertSame($expected, $found);
    }
}
