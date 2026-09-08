<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\Helper;
use TripBuilder\Repository\CityRepository;
use TripBuilder\View\TwigRenderer;

class CitiesController extends AbstractController
{
    /**
     * How many neighbours to offer, and how far away one may be.
     *
     * Both are needed. A count alone calls a city 3,855km away a neighbour --
     * that is the real distance to the nearest city from the loneliest place in
     * this database. A radius alone leaves the block empty: 231 major cities
     * for the whole world is a thin map, its median gap between neighbours is
     * 296km, and at the 500km a denser dataset can afford, 85% of cities would
     * have fewer than five to show.
     */
    private const int NEARBY_LIMIT = 8;
    private const int NEARBY_MAX_KM = 1000;

    public function show(): void
    {
        $slug = $this->slug();
        $code = self::codeFrom($slug);

        if ($code === null) {
            $this->notFound();

            return;
        }

        try {
            $cities = new CityRepository($this->connection());
            $city = $cities->byCode($code);

            if ($city === null) {
                $this->notFound();

                return;
            }

            // One city, one address. The code is what found the page, so any
            // name in front of it would serve the same content -- which is two
            // URLs for one thing unless one of them is made to point at the
            // other. 301 rather than 302: this is how the page is spelled, not
            // where it happens to be today.
            $canonical = Helper::citySlug((string) $city['name'], (string) $city['code']);

            if ($slug !== $canonical) {
                $this->bounce('/cities/' . $canonical, 301);

                return;
            }

            echo new TwigRenderer()->renderPage('cities/view.html.twig', [
                'city' => $city,
                'city_airports' => $cities->airports($code),
                'nearby' => self::addressable($cities->nearby($code, self::NEARBY_LIMIT, self::NEARBY_MAX_KM)),
            ]);
        } catch (Throwable $e) {
            error_log('City page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading this city. Please try again later.';
        }
    }

    /**
     * Give each city the address it is reached at.
     *
     * Built here rather than selected: a slug is how this app spells a name,
     * which is a view concern and not something the database should be asked
     * to store a second copy of.
     *
     * @param list<array<string, mixed>> $cities
     * @return list<array<string, mixed>>
     */
    private static function addressable(array $cities): array
    {
        return array_map(
            static fn(array $city): array => $city + [
                'slug' => Helper::citySlug((string) $city['name'], (string) $city['code']),
            ],
            $cities,
        );
    }

    /**
     * The slug out of /cities/<slug>.
     */
    private function slug(): string
    {
        // Returned as written, not folded. A URL that has been through a mail
        // client, a spreadsheet or somebody's capitals still names the city --
        // the lookup lower-cases it -- but the canonical check has to see the
        // capitals to send them somewhere, or /cities/LONDON-LON quietly serves
        // the same page at a second address.
        return preg_match('#^/cities/([A-Za-z0-9-]+)$#', $this->request->path(), $match) === 1
            ? $match[1]
            : '';
    }

    /**
     * The IATA code off the end of a slug, or null when there is not one.
     *
     * Read from the end rather than the start, because a city name can hold as
     * many hyphens as it likes -- "coolangatta-gold-coast-ool" is one of ours.
     * Three characters, which is what an IATA code is; anything else is not a
     * mistyped city, it is not a city.
     */
    private static function codeFrom(string $slug): ?string
    {
        $slug = mb_strtolower($slug);
        $code = substr($slug, -3);

        // Each hyphen-separated word, then the code. The first spelling of
        // this was `[a-z0-9]{2,}-[a-z0-9]{3}`, which allows exactly one word
        // and so turned away every city whose name has two -- Tel Aviv-Yafo,
        // Kiev/Kyiv, Coolangatta (Gold Coast) -- while montreal-ymq worked and
        // hid it.
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*-[a-z0-9]{3}$/', $slug) === 1
            ? strtoupper($code)
            : null;
    }
}
