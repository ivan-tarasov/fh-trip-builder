<?php

declare(strict_types=1);

namespace TripBuilder\View;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Api\Flights\FareRules;
use TripBuilder\BookingStatus;
use TripBuilder\CabinClass;
use TripBuilder\Currency;
use TripBuilder\Money;
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
    public function booking(array $row, array $passengers = [], ?int $travellerCount = null): ?array
    {
        $stored = StoredItinerary::fromJson($row['flight_outbound'] ?? null);

        if ($stored === null) {
            return null;
        }

        $outbound = $this->itinerary->direction($stored)['direction'];
        $storedReturn = StoredItinerary::fromJson($row['flight_return'] ?? null);
        $return = $storedReturn === null ? null : $this->itinerary->direction($storedReturn)['direction'];

        // Per direction, not per booking: on a round trip the outbound is
        // usually behind you while the return is still ahead, and a page that
        // can only say "past" for the whole booking cannot show that.
        $outbound['flown'] = $this->hasFlown($stored);

        if ($return !== null && $storedReturn !== null) {
            $return['flown'] = $this->hasFlown($storedReturn);
        }

        $status = BookingStatus::fromRow($row['status'] ?? null);
        $reference = trim((string) ($row['reference'] ?? ''));

        // The stamp the column was copied from at checkout, so the fallback is
        // the same number rather than a guess.
        $startsAt = $this->time($row['departure_time'] ?? null)
            ?? $this->time($stored->segments[0]->depart->date_time ?? null);
        $endsAt = $this->endsAt($storedReturn ?? $stored);

        $base = (float) ($row['price_base'] ?? 0);
        $tax = (float) ($row['price_tax'] ?? 0);

        // The currency the buyer was quoted in, at the rate they were quoted
        // at, both off the row. Not the visitor's cookie: a booking made in
        // yen last March reads in yen forever, and re-pricing it at today's
        // rate would quietly restate what somebody agreed to pay. This is the
        // one place on the site that must ignore the switcher.
        //
        // A row written before those columns existed defaults to CAD at 1,
        // which is the truth about it rather than a fallback.
        $money = self::moneyFor($row);

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
            // The lead's name, and how many others are on the booking. The list
            // page knows only the count -- it does not join -- and the detail
            // page has everyone; either way a party of four stops reading as a
            // trip for one.
            'passenger_summary' => $this->passengerSummary(
                trim(($row['passenger_first'] ?? '') . ' ' . ($row['passenger_last'] ?? '')),
                $passengers === [] ? $travellerCount : count($passengers),
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
            'price_total' => $base + $tax > 0 ? $this->itinerary->priceParts($base + $tax, $money) : null,
            'price_base' => $this->itinerary->priceParts($base, $money),
            'price_tax' => $this->itinerary->priceParts($tax, $money),
            'outbound' => $outbound,
            'return' => $return,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            // On the last arrival, not the departure: a round trip whose
            // outbound has flown still has a flight to catch, and filing it
            // under "past" hides the half the traveller still needs.
            'is_past' => $endsAt !== null && $endsAt < $this->now,
            'departs_in' => $this->departsIn($startsAt),
            // Whole days to departure, for grouping and for marking a trip
            // that is close enough to need packing for. Null once it has gone,
            // and on a row whose dates would not parse.
            'days_until' => $this->daysUntil($startsAt),
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
     * "Ada Lovelace" alone, or "Ada Lovelace + 2" when others are travelling.
     *
     * The count is null for a booking made before travellers were rows of their
     * own, and 1 for a party of one; both say just the name, because there is
     * nobody else to account for.
     */
    private function passengerSummary(string $lead, ?int $travellers): string
    {
        if ($travellers === null || $travellers < 2) {
            return $lead;
        }

        return sprintf('%s + %d', $lead, $travellers - 1);
    }

    /**
     * The booking's own currency, rebuilt from its two columns.
     *
     * A code the catalogue no longer lists -- a currency dropped from the menu
     * after somebody booked in it -- falls back to the base currency rather
     * than throwing. The figures on the row are Canadian dollars either way, so
     * that reads as an unconverted price rather than a wrong one.
     *
     * @param array<string, mixed> $row
     */
    private static function moneyFor(array $row): Money
    {
        $currency = Currency::tryFrom($row['currency'] ?? null);
        $rate = (float) ($row['currency_rate'] ?? 1);

        return $currency === null || $rate <= 0
            ? Money::base()
            : new Money($currency, $rate);
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
     * Whole days from today to the departure, or null if there is no departure
     * still ahead to count to.
     */
    private function daysUntil(?DateTimeImmutable $startsAt): ?int
    {
        if ($startsAt === null || $startsAt <= $this->now) {
            return null;
        }

        // From midnight to midnight: a flight tomorrow morning is "1 day away"
        // however late tonight the page is being read.
        return (int) $this->now->setTime(0, 0)->diff($startsAt->setTime(0, 0))->days;
    }

    /**
     * Whether this direction is behind us -- its last arrival has passed.
     */
    private function hasFlown(object $itinerary): bool
    {
        $endsAt = $this->endsAt($itinerary);

        return $endsAt !== null && $endsAt < $this->now;
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
