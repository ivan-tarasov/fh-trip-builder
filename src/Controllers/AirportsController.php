<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\Helper;
use TripBuilder\Repository\AirportRepository;
use TripBuilder\View\Directory;
use TripBuilder\View\TwigRenderer;

class AirportsController extends AbstractController
{
    /**
     * Every airport, as a directory.
     *
     * This was 254 Bootstrap cards, each with its own static map -- 254
     * requests to an external tile service to draw one page, and every card
     * repeating the country, city, code, timezone, coordinates and altitude
     * that the airport's own page now says properly. The cards were the only
     * place any of that was shown, which is why they were built that way; they
     * are not any more.
     *
     * Same component the cities and countries use. A directory is what a list
     * of 254 named things wants to be: you arrive knowing the name.
     */
    public function index(): void
    {
        try {
            echo new TwigRenderer()->renderPage('airports/view.html.twig', [
                'groups' => Directory::byLetter(
                    self::addressable(new AirportRepository($this->connection())->enabled(true)),
                ),
            ]);
        } catch (Throwable $e) {
            error_log('Airports page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading airports. Please try again later.';
        }
    }

    /**
     * Each airport as the directory wants it.
     *
     * `note` and `search` are both here because an airport's name is not the
     * word anybody has in mind. "Gatwick" does not say London, so the city and
     * country are printed under it; and somebody looking for it types "London",
     * which appears nowhere in "Gatwick", so the city is searchable too.
     *
     * @param list<array<string, mixed>> $airports
     * @return list<array<string, mixed>>
     */
    private static function addressable(array $airports): array
    {
        return array_map(
            static function (array $airport): array {
                $title = (string) $airport['title'];
                $code = (string) $airport['code'];

                return [
                    'name' => $title,
                    'code' => $code,
                    'url' => Helper::airportUrl($title, $code),
                    'note' => $airport['city'] . ', ' . $airport['country'],
                    'search' => $title . ' ' . $airport['city'] . ' ' . $airport['country'],
                ];
            },
            $airports,
        );
    }
}
