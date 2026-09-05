<?php

declare(strict_types=1);

namespace TripBuilder\View;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Api\Flights\FareRules;
use TripBuilder\BookingStatus;
use TripBuilder\CabinClass;
use TripBuilder\TripType;

/**
 * One booking, in the shape every page that shows one renders from.
 *
 * The same stored JSON used to be reduced three different ways -- once for the
 * bookings list, once for the confirmation page, and a stops label copied
 * wholesale out of ItineraryPresenter -- and none of the three could draw the
 * itinerary the booking had been carrying all along. This is the one shape, and
 * its two direction keys are exactly what the search cards already take, so a
 * booking draws through the same partials as a search result.
 *
 * A pure mapper: no database, no session, no request. Which list a booking
 * belongs in and how the lists are ordered is the page's business, not this
 * class's -- it only reports the facts those decisions are made from.
 */
final readonly class BookingPresenter
{
    /** What each stored type is called on screen. */
    private const array PASSENGER_TYPES = ['A' => 'Adult', 'C' => 'Child', 'I' => 'Infant'];

    public function __construct(
        private ItineraryPresenter $itinerary = new ItineraryPresenter(),
        private DateTimeImmutable $now = new DateTimeImmutable(),
    ) {}

    /**
     * Null when the outbound will not build, so the caller skips the row rather
     * than draw a booking with no flights in it.
     *
     * @param array<string, mixed> $row a bookings row as the repository returns it
     * @param list<array<string, mixed>> $passengers rows from booking_passengers, lead first
     * @return array<string, mixed>|null
     */
    public function booking(array $row, array $passengers = []): ?array
    {
        $stored = StoredItinerary::fromJson($row['flight_outbound'] ?? null);

        if ($stored === null) {
            return null;
        }

        $outbound = $this->itinerary->direction($stored)['direction'];
        $storedReturn = StoredItinerary::fromJson($row['flight_return'] ?? null);
        $return = $storedReturn === null ? null : $this->itinerary->direction($storedReturn)['direction'];

        $status = BookingStatus::fromRow($row['status'] ?? null);
        $reference = trim((string) ($row['reference'] ?? ''));

        // The stamp the column was copied from at checkout, so the fallback is
        // the same number rather than a guess.
        $startsAt = $this->time($row['departure_time'] ?? null)
            ?? $this->time($stored->segments[0]->depart->date_time ?? null);
        $endsAt = $this->endsAt($storedReturn ?? $stored);

        $base = (float) ($row['price_base'] ?? 0);
        $tax = (float) ($row['price_tax'] ?? 0);

        return [
            'id' => (int) $row['id'],
            // Empty on rows written before checkout issued one. Absent, not
            // invented: a reference is what somebody quotes down a phone.
            'reference' => $reference === '' ? null : $reference,
            'status' => $status->value,
            'status_label' => $status->label(),
            'is_cancelled' => $status === BookingStatus::Cancelled,
            'created' => $row['created'] ?? null,
            // The lead, from the booking's own row -- the bookings list shows a
            // name for every row and reads them in one query.
            'passenger' => trim(($row['passenger_first'] ?? '') . ' ' . ($row['passenger_last'] ?? '')),
            // Everyone, when the caller has fetched them. Empty for the list
            // page, which does not join, and for rows written before a booking
            // could carry more than one traveller.
            'passengers' => array_map(
                static fn(array $p): array => [
                    'name' => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
                    'type' => self::PASSENGER_TYPES[$p['type'] ?? 'A'] ?? 'Adult',
                    'dob' => $p['dob'] ?? null,
                ],
                $passengers,
            ),
            'contact_email' => $row['contact_email'] ?? null,
            'contact_phone' => $row['contact_phone'] ?? null,
            'fare_brand' => $row['fare_brand'] ?? null,
            'fare_rules' => $this->fareRules($row['fare_rules'] ?? null),
            'card_brand' => $row['card_brand'] ?? null,
            'card_last4' => $row['card_last4'] ?? null,
            // From the columns, never from the segments. The per-leg prices in
            // the JSON are a search price from an older pricing pass and no card
            // was ever charged against them, so a row that predates the columns
            // reports no price rather than a total nobody paid.
            'price_total' => $base + $tax > 0 ? $this->itinerary->priceParts($base + $tax) : null,
            'price_base' => $this->itinerary->priceParts($base),
            'price_tax' => $this->itinerary->priceParts($tax),
            'outbound' => $outbound,
            'return' => $return,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            // On the last arrival, not the departure: a round trip whose
            // outbound has flown still has a flight to catch, and filing it
            // under "past" hides the half the traveller still needs.
            'is_past' => $endsAt !== null && $endsAt < $this->now,
            'departs_in' => $this->departsIn($startsAt),
            'rebook' => $this->rebook($stored, $storedReturn),
        ];
    }

    /**
     * The route this booking flew, in the search form's own parameter names, so
     * a card can offer it back as a fresh search for a new date.
     *
     * The cabin and trip type come from what was actually bought rather than the
     * form's defaults -- offering a business round trip back as an economy
     * one-way is a worse answer than not offering it at all.
     *
     * @return array<string, string>
     */
    private function rebook(object $outbound, ?object $storedReturn): array
    {
        $segments = $outbound->segments;
        $last = $segments[count($segments) - 1];
        $cabin = CabinClass::tryFromCode((string) ($segments[0]->cabin_code ?? ''));

        return [
            'from' => (string) ($segments[0]->depart->airport_code ?? ''),
            'to' => (string) ($last->arrive->airport_code ?? ''),
            'triptype' => ($storedReturn === null ? TripType::Oneway : TripType::Roundtrip)->value,
            'class' => ($cabin ?? CabinClass::Economy)->value,
        ];
    }

    /**
     * The rules a booking was sold under, as the page lists them.
     *
     * Null for a row written before the column existed. Those cannot be
     * recovered -- the legs they were folded from are long deleted -- so the
     * page says nothing rather than guessing from a brand name.
     *
     * @return list<array{text: string, allowed: bool}>|null
     */
    private function fareRules(mixed $stored): ?array
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        $data = json_decode($stored, true);

        return is_array($data) ? FareRules::fromRow($data)->lines() : null;
    }

    /**
     * The last arrival of a direction, which is when the trip is actually over.
     */
    private function endsAt(object $itinerary): ?DateTimeImmutable
    {
        $segments = $itinerary->segments;

        return $this->time($segments[count($segments) - 1]->arrive->date_time ?? null);
    }

    /**
     * How near the departure is, or null once it has gone.
     *
     * Rendered server-side and deliberately coarse: a booking days away does not
     * need a ticking clock, and the nearest useful thing to say about one an
     * hour out is that it is close.
     */
    private function departsIn(?DateTimeImmutable $startsAt): ?string
    {
        if ($startsAt === null || $startsAt <= $this->now) {
            return null;
        }

        $minutes = (int) round(($startsAt->getTimestamp() - $this->now->getTimestamp()) / 60);

        // Whole phrases rather than a fragment a template prefixes: "departing
        // soon" and "in 40 days" do not take the same lead-in.
        return match (true) {
            $minutes < 90 => 'Departing soon',
            $minutes < 1440 => sprintf('Departs in %d hours', (int) round($minutes / 60)),
            $minutes < 2880 => 'Departs tomorrow',
            default => sprintf('Departs in %d days', (int) floor($minutes / 1440)),
        };
    }

    private function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
