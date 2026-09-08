<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\Helper;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\Repository\CityRepository;
use TripBuilder\Repository\CountryRepository;
use TripBuilder\Routes;

class SitemapController extends AbstractController
{
    /** Routed, public, and not documents anybody reads. */
    private const array NOT_PAGES = ['/sitemap.xml', '/robots.txt'];

    /**
     * Every page worth finding, for a crawler that would rather be told.
     *
     * There are 689 of them and 683 are a city, a country, an airport or an
     * airline, which is why this is generated rather than a file on disk: the
     * list is rows in a table, and a checked-in copy would be wrong the first
     * time a route is added.
     *
     * The pages are gathered by inclusion, not exclusion -- ENABLED_ROUTES
     * filtered through Routes::isPublic(), which is the same test the robots
     * meta tag makes. A new private route is therefore left out by default
     * rather than needing to be remembered.
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
                ...$this->cityPaths(),
                ...$this->countryPaths(),
                ...$this->airportPaths(),
                ...$this->airlinePaths(),
            ];
        } catch (Throwable $e) {
            // The static pages are worth serving even if the database is not
            // answering -- an empty sitemap would tell a crawler the site has
            // no pages, which is worse than an incomplete one.
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
