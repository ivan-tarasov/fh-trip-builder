<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use stdClass;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\Service\Calendar;
use TripBuilder\Service\FlightFinder;
use TripBuilder\View\BookingPresenter;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\ItineraryPresenter;
use TripBuilder\View\TwigRenderer;

class MyController extends AbstractController
{
    // Written by the browser; global.js owns the other half of this contract.
    private const string SAVED_COOKIE = 'tb_saved_flights';
    private const int SAVED_LIMIT = 50;

    /**
     * Every booking made in this browser, split by whether the trip is over.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function bookings(): void
    {
        $presenter = new BookingPresenter();
        $upcoming = [];
        $past = [];

        foreach (new BookingRepository($this->connection())->forSession(session_id()) as $row) {
            $booking = $presenter->booking($row);

            // Stored flight JSON that will not rebuild. Skip the row rather
            // than draw a booking with no flights in it.
            if ($booking === null) {
                continue;
            }

            if ($booking['is_past']) {
                $past[] = $booking;
            } else {
                $upcoming[] = $booking;
            }
        }

        // Soonest first while a trip is still ahead; most recent first once it
        // is behind. A booking with no readable dates sorts last rather than
        // disappearing into the archive.
        usort($upcoming, static fn(array $a, array $b): int => ($a['starts_at']?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($b['starts_at']?->getTimestamp() ?? PHP_INT_MAX));
        usort($past, static fn(array $a, array $b): int => ($b['ends_at']?->getTimestamp() ?? 0)
            <=> ($a['ends_at']?->getTimestamp() ?? 0));

        echo new TwigRenderer()->renderPage('my/bookings/view.html.twig', [
            'upcoming' => $upcoming,
            'past' => $past,
            // From what was built, not from what was read: a page whose every
            // row was skipped has nothing to show and needs the empty state.
            'has_rows' => $upcoming !== [] || $past !== [],
        ]);
    }

    /**
     * One booking on its own page, with every itinerary already open.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function booking(): void
    {
        $row = $this->findRow();
        $booking = $row === null ? null : new BookingPresenter()->booking($row);

        if ($booking === null) {
            $this->bounce('/my/bookings');

            return;
        }

        echo new TwigRenderer()->renderPage('my/bookings/detail.html.twig', [
            'booking' => $booking,
            // Overrides the trail derived from the path, which would otherwise
            // end on the row id. Matches the <h1> below it, including for the
            // rows written before checkout issued references.
            'breadcrumbs' => Breadcrumbs::trail(
                $this->request->path(),
                $booking['reference'] ?? 'Your booking',
            ),
        ]);
    }

    /**
     * The booking as a calendar file, one event per flight.
     *
     * @throws Exception
     */
    public function calendar(): void
    {
        $row = $this->findRow();

        if ($row === null) {
            $this->bounce('/my/bookings');

            return;
        }

        // The stored stamps are local wall-clock with no zone, so the calendar
        // is built from the raw row and the airports table rather than from the
        // presenter, whose times are already formatted for display.
        $calendar = new Calendar($this->connection())->forBooking($row);

        if ($calendar === null) {
            $this->bounce('/my/bookings');

            return;
        }

        $name = trim((string) $row['reference']) ?: (string) $row['id'];

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.ics"');

        echo $calendar;
    }

    /**
     * The booking row named by the path, scoped to this session so an id from
     * somebody else's browser resolves to nothing.
     *
     * The id is read back off the path rather than handed down from the router,
     * which keeps the routing table a plain map of address to action and stops
     * a second piece of global state existing just to carry one integer.
     *
     * @return array<string, mixed>|null
     */
    private function findRow(): ?array
    {
        if (preg_match('#/my/bookings/(\d+)#', $this->request->path(), $match) !== 1) {
            return null;
        }

        return new BookingRepository($this->connection())->findForSession((int) $match[1], session_id());
    }

    /**
     * Flights the visitor kept for later.
     *
     * The list itself is a cookie written by the browser (see global.js): each
     * entry is an itinerary's ordered leg ids, which is everything needed to
     * rebuild it. Nothing about the flight is stored — prices and times are
     * read fresh here, so a saved card can never show a stale fare.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function saved(): void
    {
        $finder = new FlightFinder($this->connection());
        $presenter = new ItineraryPresenter();
        $flights = [];

        foreach ($this->savedKeys() as $key) {
            $ids = array_map(intval(...), explode('-', $key));
            $itinerary = $finder->itinerary($ids);

            // A saved flight can go stale — the search data is regenerated, or
            // the legs no longer chain. Drop those rather than draw a broken card.
            if ($itinerary === null) {
                continue;
            }

            $decoded = json_decode((string) json_encode($itinerary), false);

            if (! $decoded instanceof stdClass) {
                continue;
            }

            $built = $presenter->direction($decoded->itinerary);
            $direction = $built['direction'];

            $flights[] = [
                'key' => $key,
                'price' => $presenter->priceParts(
                    (float) $decoded->price_base + (float) $decoded->price_tax,
                ),
                'itinerary' => $direction,
                'search_url' => $this->searchUrl($decoded->itinerary),
            ];
        }

        echo new TwigRenderer()->renderPage('my/saved/view.html.twig', [
            'flights' => $flights,
            'has_rows' => $flights !== [],
        ]);
    }

    /**
     * The saved-flight keys from the cookie, in the order they were saved.
     *
     * The cookie is written by the browser, so treat it as untrusted input: only
     * keys that are hyphen-separated integers survive, and the list is capped so
     * a hand-edited cookie cannot turn one page render into thousands of queries.
     *
     * @return list<string>
     */
    private function savedKeys(): array
    {
        $raw = json_decode($this->request->cookies->str(self::SAVED_COOKIE), true);

        if (!is_array($raw)) {
            return [];
        }

        $keys = [];

        foreach ($raw as $key) {
            if (is_string($key) && preg_match('/^\d{1,19}(-\d{1,19})*$/', $key) === 1) {
                $keys[$key] = $key;
            }
        }

        return array_values(array_slice($keys, 0, self::SAVED_LIMIT));
    }

    /**
     * A link back to a fresh search for the same route and departure date, so a
     * saved flight is a starting point rather than a dead end.
     */
    private function searchUrl(object $itinerary): string
    {
        $segments = $itinerary->segments;
        $first = $segments[0];
        $last = $segments[array_key_last($segments)];

        return '/search?' . http_build_query([
            'from' => $first->depart->airport_code,
            'to' => $last->arrive->airport_code,
            'depart' => date('Y-m-d', strtotime($first->depart->date_time)),
            'triptype' => 'oneway',
            'class' => 'economy',
        ]);
    }

}
