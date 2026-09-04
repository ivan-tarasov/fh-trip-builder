<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use stdClass;
use TripBuilder\Csrf;
use TripBuilder\Helper;
use TripBuilder\Repository\BookingRepository;
use TripBuilder\CabinClass;
use TripBuilder\Repository\FareBrandRepository;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Service\FlightFinder;
use TripBuilder\View\ItineraryPresenter;
use TripBuilder\View\TwigRenderer;

/**
 * Checkout: the page that turns a chosen itinerary into a booking.
 *
 * The itinerary arrives as the same leg ids the search passes around, so the
 * page is a link rather than a modal — refreshable, shareable, and reachable
 * with the browser's Back button. Everything that matters is resolved from
 * those ids server-side: the price shown is the price in the database, never a
 * number the form sent us.
 */
class CheckoutController extends AbstractController
{
    // The cabin the itinerary was chosen in. It has to travel with the ids:
    // they name the legs but not what was being bought, and the fare depends on
    // it. Absent or unrecognised falls back to economy, as the search form does.
    private const string GET_CLASS = 'class';
    private const string GET_DEPART_ITIN = 'depart_itin';
    private const string GET_RETURN_ITIN = 'return_itin';
    private const string GET_REFERENCE = 'ref';

    // A card number that always declines, so the unhappy path can be walked
    // without a gateway. Everything else that passes a Luhn check approves.
    private const string DECLINE_CARD = '4000000000000002';

    private const array GENDERS = ['F' => 'Female', 'M' => 'Male', 'X' => 'Another / prefer not to say'];

    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function index(): void
    {
        $query = $this->request->query;

        $outboundIds = $query->ids(self::GET_DEPART_ITIN);
        $returnIds = $query->ids(self::GET_RETURN_ITIN);
        $cabin = CabinClass::fromRequest($query->nullableStr(self::GET_CLASS));

        if ($outboundIds === []) {
            $this->bounce('/');

            return;
        }

        $trip = $this->resolveTrip($outboundIds, $returnIds, $cabin);

        // Nothing to sell: the legs have been regenerated away since the
        // search, or the ids never named a real itinerary.
        if ($trip === null) {
            echo new TwigRenderer()->renderPage('checkout/gone.html.twig', [
                'search_url' => '/',
            ]);

            return;
        }

        $errors = [];
        $submitted = [];

        if ($this->request->isPost()) {
            $submitted = $this->submitted();
            $errors = $this->validate($submitted);

            if ($errors === []) {
                $reference = $this->book($trip, $submitted, $outboundIds, $returnIds, $cabin);

                $this->bounce(sprintf('/checkout/confirmation?%s=%s', self::GET_REFERENCE, $reference));

                return;
            }
        }

        echo new TwigRenderer()->renderPage('checkout/view.html.twig', [
            'trip' => $trip,
            'genders' => self::GENDERS,
            'errors' => $errors,
            'form' => $submitted,
            'csrf_token' => Csrf::token(),
            'form_action' => $this->selfUrl($outboundIds, $returnIds, $cabin),
            'change_url' => '/',
            'decline_card' => self::DECLINE_CARD,
        ]);
    }

    /**
     * @throws Exception|\Twig\Error\Error
     */
    public function confirmation(): void
    {
        $reference = strtoupper($this->request->query->str(self::GET_REFERENCE));

        $booking = preg_match('/^[A-Z0-9]{6}$/', $reference) === 1
            ? new BookingRepository($this->connection())->findByReference($reference, session_id())
            : null;

        if ($booking === null) {
            $this->bounce('/my/bookings');

            return;
        }

        $presenter = new ItineraryPresenter();
        $outbound = json_decode((string) $booking['flight_outbound'], true);
        $return = json_decode((string) $booking['flight_return'], true);

        echo new TwigRenderer()->renderPage('checkout/confirmation.html.twig', [
            'reference' => $booking['reference'],
            'email' => $booking['contact_email'],
            'passenger' => trim($booking['passenger_first'] . ' ' . $booking['passenger_last']),
            'card_brand' => $booking['card_brand'],
            'card_last4' => $booking['card_last4'],
            'price' => $presenter->priceParts(
                (float) $booking['price_base'] + (float) $booking['price_tax'],
            ),
            'outbound' => is_array($outbound) ? $this->legLines($outbound) : [],
            'return' => is_array($return) ? $this->legLines($return) : [],
            'bookings_url' => '/my/bookings',
        ]);
    }

    /**
     * The trip on offer: both directions shaped for the page, the price as the
     * database has it, and the rules the whole journey is sold under.
     *
     * Priced in `$cabin`: the same cabin the search quoted, so the total here
     * is the total the buyer was shown.
     *
     * @param list<int> $outboundIds
     * @param list<int> $returnIds
     * @return array<string, mixed>|null
     */
    private function resolveTrip(array $outboundIds, array $returnIds, CabinClass $cabin): ?array
    {
        $finder = new FlightFinder($this->connection());

        $outbound = $finder->itinerary($outboundIds, $cabin);

        if ($outbound === null) {
            return null;
        }

        $return = $returnIds === [] ? null : $finder->itinerary($returnIds, $cabin);

        if ($returnIds !== [] && $return === null) {
            return null;
        }

        $presenter = new ItineraryPresenter();
        $priceBase = (float) $outbound['price_base'] + (float) ($return['price_base'] ?? 0);
        $priceTax = (float) $outbound['price_tax'] + (float) ($return['price_tax'] ?? 0);

        return [
            'outbound' => $presenter->direction($this->asObject($outbound['itinerary'])),
            'return' => $return === null ? null : $presenter->direction($this->asObject($return['itinerary'])),
            'price_base' => $presenter->priceParts($priceBase),
            'price_tax' => $presenter->priceParts($priceTax),
            'price_total' => $presenter->priceParts($priceBase + $priceTax),
            'raw_base' => $priceBase,
            'raw_tax' => $priceTax,
            'rules' => $this->rules($outboundIds, $returnIds, $cabin),
        ];
    }

    /**
     * The finder speaks in arrays and the presenter in objects.
     *
     * The search page bridges the two by round-tripping its whole payload
     * through JSON; this does the same for one itinerary rather than teach the
     * presenter a second input shape.
     *
     * @param array<string, mixed> $itinerary
     * @throws Exception
     */
    private function asObject(array $itinerary): object
    {
        $object = json_decode((string) json_encode($itinerary), false);

        if (!$object instanceof stdClass) {
            throw new Exception('Could not shape the itinerary for rendering');
        }

        return $object;
    }

    /**
     * The fare rules per direction, each folded to the strictest of its legs.
     *
     * @param list<int> $outboundIds
     * @param list<int> $returnIds
     * @return list<array{route: string, title: string, lines: list<array{text: string, allowed: bool}>}>
     */
    private function rules(array $outboundIds, array $returnIds, CabinClass $cabin): array
    {
        $flights = new FlightRepository($this->connection());
        $brands = new FareBrandRepository($this->connection());
        $legs = $flights->legsByIds($outboundIds, $cabin);
        $out = [];

        foreach ([[$outboundIds, $legs], [$returnIds, $flights->legsByIds($returnIds, $cabin)]] as [$ids, $hydrated]) {
            if ($ids === [] || $hydrated === []) {
                continue;
            }

            $rules = $brands->rulesFor($flights->fareBrandsByIds($ids));

            if ($rules === null) {
                continue;
            }

            $out[] = [
                'route' => sprintf(
                    '%s to %s',
                    $hydrated[0]['dep_code'],
                    $hydrated[count($hydrated) - 1]['arr_code'],
                ),
                'title' => $rules->title,
                'lines' => $rules->lines(),
            ];
        }

        return $out;
    }

    /**
     * Store the booking and hand back its reference.
     *
     * The money written here comes from `$trip`, which was resolved from the
     * database — a total in the POST body would be a total the buyer chose.
     *
     * @param array<string, mixed> $trip
     * @param array<string, string> $form
     * @param list<int> $outboundIds
     * @param list<int> $returnIds
     * @throws Exception
     */
    private function book(array $trip, array $form, array $outboundIds, array $returnIds, CabinClass $cabin): string
    {
        $finder = new FlightFinder($this->connection());
        $outbound = $finder->findSegments($outboundIds, $cabin);
        $return = $returnIds === [] ? [] : $finder->findSegments($returnIds, $cabin);

        $bookings = new BookingRepository($this->connection());
        $reference = $bookings->unusedReference();
        $brands = new FareBrandRepository($this->connection());
        $strictest = $brands->rulesFor(
            [...new FlightRepository($this->connection())->fareBrandsByIds([...$outboundIds, ...$returnIds])],
        );

        $bookings->create([
            'session_id' => session_id(),
            'reference' => $reference,
            'status' => 'confirmed',
            'departure_time' => $outbound[0]['depart']['date_time'] ?? null,
            'flight_outbound' => json_encode($outbound),
            'flight_return' => $return === [] ? null : json_encode($return),
            'contact_email' => $form['email'],
            'contact_phone' => $form['phone'],
            'passenger_first' => $form['first_name'],
            'passenger_last' => $form['last_name'],
            'passenger_dob' => $form['dob'],
            'passenger_gender' => $form['gender'],
            'fare_brand' => $strictest?->title,
            'price_base' => $trip['raw_base'],
            'price_tax' => $trip['raw_tax'],
            'card_brand' => Helper::cardScheme($form['card_number']),
            // All of the card that is ever stored.
            'card_last4' => substr(preg_replace('/\D+/', '', $form['card_number']) ?? '', -4),
            'created' => date('Y-m-d H:i:s'),
        ]);

        return $reference;
    }

    /**
     * @return array<string, string>
     */
    private function submitted(): array
    {
        $body = $this->request->body;
        $field = static fn(string $key): string => $body->str($key);

        return [
            'email' => $field('email'),
            'phone' => $field('phone'),
            'first_name' => $field('first_name'),
            'last_name' => $field('last_name'),
            'dob' => $field('dob'),
            'gender' => $field('gender'),
            'card_name' => $field('card_name'),
            'card_number' => $field('card_number'),
            'card_expiry' => $field('card_expiry'),
            'card_cvv' => $field('card_cvv'),
            'billing_postcode' => $field('billing_postcode'),
            'billing_country' => $field('billing_country'),
            'accept_rules' => $field('accept_rules'),
        ];
    }

    /**
     * Every reason the form cannot be accepted, keyed by field.
     *
     * The browser checks the same things for a faster answer; this is the check
     * that counts, because the browser's can be skipped.
     *
     * @param array<string, string> $form
     * @return array<string, string>
     */
    private function validate(array $form): array
    {
        $errors = [];

        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $errors['form'] = 'That form went stale. Please try again.';
        }

        if (filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A working email address — the ticket goes there.';
        }

        // Loose on purpose: numbers, spaces, brackets, dashes and a leading +
        // covers every format people actually type.
        if (preg_match('/^\+?[\d\s()-]{7,20}$/', $form['phone']) !== 1) {
            $errors['phone'] = 'A phone number the airline can reach you on.';
        }

        foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $key => $label) {
            if (mb_strlen($form[$key]) < 2) {
                $errors[$key] = sprintf('%s, as printed on your ID.', $label);
            }
        }

        $dob = date_create_immutable($form['dob'] ?: 'invalid');
        $today = date_create_immutable('today');

        if ($dob === false || $dob >= $today || $dob < $today->modify('-120 years')) {
            $errors['dob'] = 'A date of birth in the past.';
        }

        if (!isset(self::GENDERS[$form['gender']])) {
            $errors['gender'] = 'Pick one.';
        }

        if (mb_strlen($form['card_name']) < 2) {
            $errors['card_name'] = 'The name printed on the card.';
        }

        $digits = preg_replace('/\D+/', '', $form['card_number']) ?? '';

        if (!Helper::isLuhnValid($digits)) {
            $errors['card_number'] = 'That card number is not valid.';
        }

        if (!$this->expiryInFuture($form['card_expiry'])) {
            $errors['card_expiry'] = 'An expiry date that has not passed.';
        }

        if (preg_match('/^\d{3,4}$/', $form['card_cvv']) !== 1) {
            $errors['card_cvv'] = 'The 3 or 4 digits from the card.';
        }

        if (mb_strlen($form['billing_postcode']) < 3) {
            $errors['billing_postcode'] = 'The postcode your card is registered to.';
        }

        if (preg_match('/^[A-Za-z]{2}$/', $form['billing_country']) !== 1) {
            $errors['billing_country'] = 'Pick the country.';
        }

        if ($form['accept_rules'] !== '1') {
            $errors['accept_rules'] = 'The fare rules have to be accepted before booking.';
        }

        // Last, and only once the card itself is well formed: a decline is the
        // gateway's answer, not a mistake in the form.
        if (!isset($errors['card_number']) && $digits === self::DECLINE_CARD) {
            $errors['card_number'] = 'The card was declined. Try another one.';
        }

        return $errors;
    }

    private function expiryInFuture(string $expiry): bool
    {
        if (preg_match('#^(0[1-9]|1[0-2])\s*/\s*(\d{2})$#', $expiry, $match) !== 1) {
            return false;
        }

        // Two digits, so "29" is 2029: a card that expired in 1929 is not a
        // case worth handling.
        $end = date_create_immutable(sprintf('20%s-%s-01', $match[2], $match[1]));

        return $end !== false && $end->modify('+1 month') > date_create_immutable('now');
    }

    /**
     * A stored itinerary as one line per leg, for the confirmation.
     *
     * @param list<array<string, mixed>> $segments
     * @return list<array<string, string>>
     */
    private function legLines(array $segments): array
    {
        $lines = [];

        foreach ($segments as $segment) {
            $depart = $segment['depart'] ?? [];
            $arrive = $segment['arrive'] ?? [];

            $lines[] = [
                'flight' => (string) ($segment['number'] ?? ''),
                'carrier' => (string) ($segment['carrier_name'] ?? ''),
                'from' => (string) ($depart['airport_code'] ?? ''),
                'to' => (string) ($arrive['airport_code'] ?? ''),
                'depart' => date('D, j M H:i', strtotime((string) ($depart['date_time'] ?? 'now'))),
                'arrive' => date('D, j M H:i', strtotime((string) ($arrive['date_time'] ?? 'now'))),
            ];
        }

        return $lines;
    }


    /**
     * @param list<int> $outboundIds
     * @param list<int> $returnIds
     */
    private function selfUrl(array $outboundIds, array $returnIds, CabinClass $cabin): string
    {
        $query = [self::GET_DEPART_ITIN => implode(',', $outboundIds)];

        if ($returnIds !== []) {
            $query[self::GET_RETURN_ITIN] = implode(',', $returnIds);
        }

        // The form posts back here, and the POST re-reads the cabin from the
        // query. Drop it and the trip would be repriced as economy on submit --
        // and that is the price that would be charged.
        if ($cabin !== CabinClass::Economy) {
            $query[self::GET_CLASS] = $cabin->value;
        }

        // Commas survive: they read better in an address bar and the parser
        // above splits on them either way.
        return '/checkout?' . str_replace('%2C', ',', http_build_query($query));
    }
}
