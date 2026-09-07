<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use stdClass;
use TripBuilder\Repository\BookingPassengerRepository;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\SearchUrl;
use TripBuilder\Service\Calendar;
use TripBuilder\Service\FlightFinder;
use TripBuilder\View\BookingPresenter;
use TripBuilder\View\Breadcrumbs;
use TripBuilder\View\ItineraryPresenter;
use TripBuilder\View\TwigRenderer;

class MyController extends AbstractController
{
    // Where the upcoming list is cut. A week is what somebody is packing for;
    // a month is what they are planning around. Past that the distinction stops
    // meaning anything, so there is only one more pile.
    private const int DAYS_THIS_WEEK = 7;
    private const int DAYS_THIS_MONTH = 30;

    // Written by the browser; global.js owns the other half of this contract.
    private const string SAVED_COOKIE = 'tb_saved_flights';
    private const int SAVED_LIMIT = 50;

    /**
     * Trips still ahead, grouped by how soon they are.
     *
     * Grouped by nearness rather than by calendar month. A month heading says
     * where a trip sits in the year, which is not a question anybody opens this
     * page with -- "is there anything I need to get ready for?" is, and a trip
     * four days out and one four months out want different attention. The
     * bounds do not overlap, so nothing has to be read twice to be placed.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function bookings(): void
    {
        $sorted = $this->sortedBookings();

        $this->renderBookings('active', [
            ['key' => 'week', 'title' => 'This week', 'bookings' => $sorted['week']],
            ['key' => 'month', 'title' => 'Within a month', 'bookings' => $sorted['month']],
            ['key' => 'later', 'title' => 'Later', 'bookings' => $sorted['later']],
        ], $sorted);
    }

    /**
     * Trips already flown.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function past(): void
    {
        $sorted = $this->sortedBookings();

        $this->renderBookings('past', [
            ['key' => 'past', 'title' => 'Past', 'bookings' => $sorted['past']],
        ], $sorted);
    }

    /**
     * Trips that were called off.
     *
     * Their own page rather than a third pile under the others. A cancelled
     * booking is not a trip any more, and mixing it in means every glance at
     * the list has to re-read the status of everything on it.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function cancelled(): void
    {
        $sorted = $this->sortedBookings();

        $this->renderBookings('cancelled', [
            ['key' => 'cancelled', 'title' => 'Cancelled', 'bookings' => $sorted['cancelled']],
        ], $sorted);
    }

    /**
     * @param list<array{key: string, title: string, bookings: list<array<string, mixed>>}> $groups
     * @param array<string, list<array<string, mixed>>> $sorted
     *
     * @throws \Twig\Error\Error
     */
    private function renderBookings(string $tab, array $groups, array $sorted): void
    {
        $shown = array_sum(array_map(static fn(array $group): int => count($group['bookings']), $groups));

        echo new TwigRenderer()->renderPage('my/bookings/view.html.twig', [
            'tab' => $tab,
            'groups' => $groups,
            // Every count on every page: the tab strip names them all whichever
            // side it is drawn from.
            'counts' => [
                'active' => count($sorted['week']) + count($sorted['month']) + count($sorted['later']),
                'past' => count($sorted['past']),
                'cancelled' => count($sorted['cancelled']),
            ],
            // From what was built, not from what was read: a page whose every
            // row was skipped has nothing to show and needs the empty state.
            'has_rows' => $shown > 0,
        ]);
    }

    /**
     * Every booking made in this browser, in piles and in reading order.
     *
     * @return array<string, list<array<string, mixed>>>
     *
     * @throws Exception
     */
    private function sortedBookings(): array
    {
        $presenter = new BookingPresenter();
        $upcoming = [];
        $past = [];
        $cancelled = [];

        $rows = new BookingRepository($this->connection())->forSession(session_id());

        // One count query for the page. The rows themselves are read without a
        // join, so without this a card could only ever name the lead.
        $counts = new BookingPassengerRepository($this->connection())->countsFor(
            array_map(static fn(array $row): int => (int) $row['id'], $rows),
        );

        foreach ($rows as $row) {
            $booking = $presenter->booking($row, travellerCount: $counts[(int) $row['id']] ?? null);

            // Stored flight JSON that will not rebuild. Skip the row rather
            // than draw a booking with no flights in it.
            if ($booking === null) {
                continue;
            }

            // Cancelled first, whether or not the dates have passed: a trip
            // that was called off never became a past trip.
            if ($booking['is_cancelled']) {
                $cancelled[] = $booking;
            } elseif ($booking['is_past']) {
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
        // Most recently booked first: a cancelled trip is looked up by when it
        // was bought, not by when it would have flown.
        usort($cancelled, static fn(array $a, array $b): int => strcmp(
            (string) ($b['created'] ?? ''),
            (string) ($a['created'] ?? ''),
        ));

        return self::byNearness($upcoming) + ['past' => $past, 'cancelled' => $cancelled];
    }

    /**
     * Trips ahead, split into this week, this month and later.
     *
     * A row whose dates would not parse has no `days_until` and lands in
     * "Later" -- the one pile where being wrong about the order costs nothing.
     *
     * @param list<array<string, mixed>> $upcoming
     *
     * @return array{week: list<array<string, mixed>>, month: list<array<string, mixed>>, later: list<array<string, mixed>>}
     */
    private static function byNearness(array $upcoming): array
    {
        $piles = ['week' => [], 'month' => [], 'later' => []];

        foreach ($upcoming as $booking) {
            $days = $booking['days_until'] ?? null;

            $piles[match (true) {
                $days === null => 'later',
                $days <= self::DAYS_THIS_WEEK => 'week',
                $days <= self::DAYS_THIS_MONTH => 'month',
                default => 'later',
            }][] = $booking;
        }

        return $piles;
    }

    /**
     * One booking on its own page, with every itinerary already open.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function booking(): void
    {
        $row = $this->findRow();
        $booking = $row === null
            ? null
            : new BookingPresenter()->booking(
                $row,
                new BookingPassengerRepository($this->connection())->forBooking((int) $row['id']),
            );

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

        return new SearchUrl(
            from: (string) $first->depart->airport_code,
            to: (string) $last->arrive->airport_code,
            depart: date('Y-m-d', (int) strtotime((string) $first->depart->date_time)),
            return: null,
        )->path();
    }

}
