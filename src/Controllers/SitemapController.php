<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\Helper;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\ArticleRepository;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\CountryRepository;
use TripBuilder\Repository\RouteRepository;
use TripBuilder\RouteAddress;
use TripBuilder\Routes;

class SitemapController extends AbstractController
{
    /** Routed, public, and not documents anybody reads. */
    private const array NOT_PAGES = ['/sitemap.xml', '/robots.txt'];

    /**
     * Every page worth finding, for a crawler that would rather be told.
     *
     * All but twelve of them are a place of some kind, which is why this is
     * generated rather than a file on disk: the list is rows in a table, and a
     * checked-in copy would be wrong the first time a route is added. The
     * twelve are the homepage, the four directories, the README page, and the
     * help hub with its five articles.
     *
     * Four of the five place families are listed whole -- every city, country,
     * airport and airline we sell. Routes are not, and cannot be: 42,578 city
     * pairs in this data can be flown nonstop, which is more URLs than a
     * sitemap may hold. They are listed by demand instead; see routePaths().
     *
     * The pages are gathered by inclusion, not exclusion -- ENABLED_ROUTES
     * filtered through Routes::isPublic(), which is the same test the robots
     * meta tag makes. A new private route is therefore left out by default
     * rather than needing to be remembered. The families the router holds only
     * as patterns -- places and help articles -- are enumerated from their
     * tables instead.
     *
     * No <lastmod>, <changefreq> or <priority>. Nothing here records when a
     * city page last changed, and a lastmod of "now" on every URL is a lie a
     * crawler learns to ignore; the other two Google has said outright it does
     * not use.
     */
    public function index(): void
    {
        header('Content-Type: application/xml; charset=utf-8');

        try {
            $urls = [
                ...$this->staticPaths(),
                ...$this->articlePaths(),
                ...$this->cityPaths(),
                ...$this->countryPaths(),
                ...$this->airportPaths(),
                ...$this->airlinePaths(),
                ...$this->routePaths(),
            ];
        } catch (Throwable $e) {
            // The routed pages are worth serving even if the database is not
            // answering -- an empty sitemap would tell a crawler the site has
            // no pages, which is worse than an incomplete one.
            //
            // staticPaths() is the only contributor that can run here, and it
            // has to stay that way: this is a catch handler, so anything that
            // throws inside it throws uncaught and the crawler gets a 500
            // instead of a short sitemap. That is why the help articles moved
            // out of it when they became rows.
            error_log('Sitemap places failed: ' . $e->getMessage());
            $urls = $this->staticPaths();
        }

        $origin = $this->origin();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $path) {
            printf("  <url><loc>%s</loc></url>\n", htmlspecialchars($origin . $path, ENT_XML1));
        }

        echo '</urlset>' . "\n";
    }

    /**
     * Where a crawler is asked not to go, and where to find the map.
     *
     * Generated for the same reason the sitemap is: the paths to disallow are
     * Routes::PRIVATE_PREFIXES, and a checked-in file would be a second copy of
     * a list that already exists in code.
     *
     * Disallow here and noindex on the page are not the same instruction and
     * both are wanted. Disallow says "do not spend a request on this", which
     * keeps a crawler out of the search space -- there are more possible search
     * URLs than there are flights. noindex says "do not keep it", which is what
     * answers for a URL somebody linked to anyway.
     */
    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        echo "User-agent: *\n";

        foreach (Routes::PRIVATE_PREFIXES as $prefix) {
            printf("Disallow: %s\n", $prefix . '/');
        }

        // Endpoints, which answer JSON to a POST and nothing at all to a GET.
        echo "Disallow: /api/\n";
        echo "Disallow: /ajax/\n";

        printf("\nSitemap: %s/sitemap.xml\n", $this->origin());
    }

    /**
     * The fixed pages, taken from the route table so a new one is listed by
     * having been routed rather than by being remembered here.
     *
     * Touches no database, which is the whole reason it exists separately:
     * index() calls this from inside its catch handler, so it is the one
     * contributor that must be able to run when nothing else can.
     *
     * The help articles used to be listed here for that same reason. They are
     * rows now, so they are in articlePaths() with the place families, and a
     * sitemap served while the database is down no longer names them. That is
     * a real loss, accepted: the alternative is a second copy of the slugs
     * kept in step by hand.
     *
     * @return list<string>
     */
    private function staticPaths(): array
    {
        $paths = array_filter(
            array_keys(Routes::ENABLED_ROUTES),
            static fn(string $path): bool => Routes::isPublic($path),
        );

        // Neither of these is a page. They are public and they are routed, so
        // the filter above keeps them; a sitemap listing itself and a robots
        // file is a sitemap describing its own plumbing.
        return array_values(array_diff($paths, self::NOT_PAGES));
    }

    /**
     * One entry per help article.
     *
     * Named individually because the router holds this family only as a
     * pattern -- `#^/help/[A-Za-z-]+$#` describes the shape of an article's
     * address and cannot enumerate which ones exist. Read from the same table
     * the pages themselves read, so a sixth article is listed by existing and
     * a disabled one is not listed at all.
     *
     * @return list<string>
     */
    private function articlePaths(): array
    {
        $paths = [];

        foreach (array_keys(new ArticleRepository($this->connection())->all()) as $slug) {
            $paths[] = '/help/' . $slug;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function cityPaths(): array
    {
        return array_map(
            static fn(array $city): string => '/city/' . Helper::placeSlug(
                (string) $city['name'],
                (string) $city['code'],
            ),
            new CityRepository($this->connection())->all(),
        );
    }

    /**
     * @return list<string>
     */
    private function countryPaths(): array
    {
        return array_map(
            static fn(array $country): string => '/country/' . Helper::placeSlug(
                (string) $country['name'],
                (string) $country['code'],
            ),
            new CountryRepository($this->connection())->sellable(),
        );
    }

    /**
     * @return list<string>
     */
    private function airportPaths(): array
    {
        return array_map(
            static fn(array $airport): string => Helper::airportUrl(
                (string) $airport['title'],
                (string) $airport['code'],
            ),
            new AirportRepository($this->connection())->enabled(true),
        );
    }

    /**
     * @return list<string>
     */
    private function airlinePaths(): array
    {
        return array_map(
            static fn(array $airline): string => Helper::airlineUrl(
                (string) $airline['name'],
                (string) $airline['code'],
            ),
            new AirlineRepository($this->connection())->sellable(),
        );
    }

    /**
     * The routes worth crawling, which is not all of them.
     *
     * The one family with more pages than a sitemap can carry, so what is
     * listed is what somebody has actually looked for, off the search table.
     * The rest stay reachable and answer perfectly well; they are simply not
     * advertised, which is the honest thing to do with a page nobody has ever
     * asked for.
     *
     * The only count here that moves on its own, which is the point of it.
     *
     * @return list<string>
     */
    private function routePaths(): array
    {
        return array_map(
            static fn(array $route): string => RouteAddress::path(
                (string) $route['from_name'],
                (string) $route['to_name'],
            ),
            new RouteRepository($this->connection())->searched(),
        );
    }

    /**
     * Where this site is, according to whoever asked.
     *
     * A sitemap must carry absolute URLs -- the one place in this app that has
     * no choice, which is why there is still no configured host. Taken from the
     * request, so the file always names the host it was fetched from: a crawler
     * that asks example.com is told about example.com.
     */
    private function origin(): string
    {
        $host = $this->request->header('Host') ?? 'localhost';

        return ($this->request->isSecure() ? 'https' : 'http') . '://' . $host;
    }
}
