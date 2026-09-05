<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Http\Input;

/**
 * What a search is, and how it is spelled in the address bar.
 *
 * A search used to be nine query parameters. Only five of them said anything
 * about which flights were wanted; the rest -- sort, paging, filters, which leg
 * has been chosen -- describe the screen rather than the trip. Those stay in the
 * query string. These five become one readable segment:
 *
 *     /search/YUL1609LHR3009W1
 *      from --^  ^     ^   ^ ^^-- 1 adult
 *       16 Sep --'     |   |'-- premium economy
 *                to ---'   '-- returning 30 Sep
 *
 * Trip type is not stored: a return date is what makes a trip a round trip.
 *
 * The cabin letter is always written, even for economy where the format this
 * follows omits it. It is what keeps the grammar unambiguous -- after the
 * destination, a digit can only begin a return date and a letter can only be the
 * cabin -- so nothing has to count digits to decide whether a trip returns.
 */
final readonly class SearchUrl
{
    /**
     * Airport codes are three characters but not always three *letters*:
     * `A39` is a real row in the airports table. Matching [A-Z]{3} would make
     * that airport unaddressable.
     */
    private const string PATTERN = '#^/?search/'
        . '(?<from>[A-Z0-9]{3})(?<depart>\d{4})(?<to>[A-Z0-9]{3})(?<return>\d{4})?'
        . '(?<cabin>[YWCF])(?<pax>\d{1,3})$#';

    private const int MAX_PASSENGERS = 9;

    public function __construct(
        public string $from,
        public string $to,
        public string $depart,
        public ?string $return,
        public CabinClass $cabin = CabinClass::Economy,
        public int $adults = 1,
        public int $children = 0,
        public int $infants = 0,
    ) {}

    /**
     * Read a path segment, or null when it is not one.
     *
     * Null rather than a partial object, so a caller can fall through to the
     * older query-string form instead of searching for something nobody asked
     * for. This is also the first format check `from`, `to` and `depart` have
     * ever had -- the query-string path validated none of them.
     */
    public static function parse(string $path, ?DateTimeImmutable $now = null): ?self
    {
        if (preg_match(self::PATTERN, trim($path), $match) !== 1) {
            return null;
        }

        $now ??= new DateTimeImmutable();
        $depart = self::resolveDate($match['depart'], $now);

        if ($depart === null) {
            return null;
        }

        $return = null;

        if ($match['return'] !== '') {
            // Anchored to the departure, not to today: a trip leaving on 30
            // December and back on 5 January returns in the following year.
            $return = self::resolveDate($match['return'], new DateTimeImmutable($depart));

            if ($return === null) {
                return null;
            }
        }

        $pax = str_split($match['pax']);

        return new self(
            from: $match['from'],
            to: $match['to'],
            depart: $depart,
            return: $return,
            cabin: CabinClass::tryFromCode($match['cabin']) ?? CabinClass::Economy,
            adults: max(1, (int) ($pax[0] ?? 1)),
            children: (int) ($pax[1] ?? 0),
            infants: (int) ($pax[2] ?? 0),
        );
    }

    /**
     * Read the older query-string form, so links already shared keep working.
     */
    public static function fromQuery(Input $query): ?self
    {
        $from = strtoupper($query->str((string) Config::get('search.form.input.depart_place')));
        $to = strtoupper($query->str((string) Config::get('search.form.input.arrive_place')));
        $depart = self::validDate($query->str((string) Config::get('search.form.input.depart_date')));

        if (!self::validCode($from) || !self::validCode($to) || $depart === null) {
            return null;
        }

        // A return date is what makes it a round trip, but an explicit one-way
        // has to win: the form leaves a stale return date in place when the
        // traveller switches the tab back.
        $oneway = TripType::fromRequest($query->nullableStr((string) Config::get('search.form.input.triptype')))
            === TripType::Oneway;
        $return = $oneway
            ? null
            : self::validDate($query->str((string) Config::get('search.form.input.return_date')));

        return new self(
            from: $from,
            to: $to,
            depart: $depart,
            return: $return,
            cabin: CabinClass::fromRequest($query->nullableStr((string) Config::get('search.form.input.class'))),
            adults: self::paxFrom($query, 'adults', 1),
            children: self::paxFrom($query, 'children', 0),
            infants: self::paxFrom($query, 'infants', 0),
        );
    }

    public function path(): string
    {
        return sprintf(
            '/search/%s%s%s%s%s%s',
            $this->from,
            self::shortDate($this->depart),
            $this->to,
            $this->return === null ? '' : self::shortDate($this->return),
            $this->cabin->code(),
            $this->passengers(),
        );
    }

    public function tripType(): TripType
    {
        return $this->return === null ? TripType::Oneway : TripType::Roundtrip;
    }

    /**
     * The trailing block, trimmed of zeroes it does not need: a lone adult is
     * `1`, not `100`.
     */
    private function passengers(): string
    {
        if ($this->infants > 0) {
            return sprintf('%d%d%d', $this->adults, $this->children, $this->infants);
        }

        return $this->children > 0
            ? sprintf('%d%d', $this->adults, $this->children)
            : (string) $this->adults;
    }

    /**
     * DDMM to a full date, taking the next occurrence -- the same reading a
     * traveller gives "the 16th of September" in December.
     */
    private static function resolveDate(string $ddmm, DateTimeImmutable $after): ?string
    {
        $day = (int) substr($ddmm, 0, 2);
        $month = (int) substr($ddmm, 2, 2);
        $year = (int) $after->format('Y');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            // checkdate first: a bare DateTimeImmutable would roll 31 February
            // into March rather than rejecting it, and 29 February is only a
            // date in some of the years this loop tries.
            if (checkdate($month, $day, $year + $attempt)) {
                $date = sprintf('%04d-%02d-%02d', $year + $attempt, $month, $day);

                if ($date >= $after->format('Y-m-d')) {
                    return $date;
                }
            }
        }

        return null;
    }

    private static function shortDate(string $date): string
    {
        return date('dm', (int) strtotime($date));
    }

    private static function validCode(string $code): bool
    {
        return preg_match('/^[A-Z0-9]{3}$/', $code) === 1;
    }

    /**
     * A date only counts if the calendar agrees: strtotime() would happily read
     * `2026-02-31` and hand back 3 March.
     */
    private static function validDate(string $date): ?string
    {
        try {
            $parsed = new DateTimeImmutable($date);
        } catch (Throwable) {
            return null;
        }

        return $parsed->format('Y-m-d') === $date ? $date : null;
    }

    private static function paxFrom(Input $query, string $key, int $default): int
    {
        return $query->intWithin(
            (string) Config::get('search.form.input.' . $key),
            $default,
            0,
            self::MAX_PASSENGERS,
        );
    }
}
