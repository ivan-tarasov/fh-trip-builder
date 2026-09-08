<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Throwable;
use TripBuilder\Helper;
use TripBuilder\Repository\AirlineRepository;
use TripBuilder\View\Directory;
use TripBuilder\View\TwigRenderer;

class AirlinesController extends AbstractController
{
    /**
     * Every airline, as a directory.
     *
     * This was 105 Bootstrap cards, each carrying a logo, a phone number and a
     * website -- and each of those was the only place any of it was shown,
     * which is why the cards held them. The airline's own page holds them now,
     * along with where it is based and where it flies, so the cards were
     * repeating three fields and reaching nothing.
     *
     * Same component the cities, countries and airports use. A directory is
     * what a list of 105 named things wants to be: you arrive knowing the name.
     */
    public function index(): void
    {
        try {
            echo new TwigRenderer()->renderPage('airlines/view.html.twig', [
                'groups' => Directory::byLetter(
                    self::addressable(new AirlineRepository($this->connection())->sellable()),
                ),
            ]);
        } catch (Throwable $e) {
            error_log('Airlines page failed: ' . $e->getMessage());
            echo 'Something went wrong while loading airlines. Please try again later.';
        }
    }

    /**
     * Each airline as the directory wants it.
     *
     * The country is printed under the name and is searchable with it. Both for
     * the same reason the airports page does it: "Luxair" and "Cebu Pacific"
     * are names most people meet for the first time on a results page, and
     * "Luxembourg" is the word that tells them which airline they were looking
     * at.
     *
     * @param list<array<string, mixed>> $airlines
     * @return list<array<string, mixed>>
     */
    private static function addressable(array $airlines): array
    {
        return array_map(
            static function (array $airline): array {
                $name = (string) $airline['name'];
                $code = (string) $airline['code'];
                $country = (string) $airline['country'];

                return [
                    'name' => $name,
                    'code' => $code,
                    'url' => Helper::airlineUrl($name, $code),
                    'note' => $country,
                    'search' => $name . ' ' . $country,
                ];
            },
            $airlines,
        );
    }
}
