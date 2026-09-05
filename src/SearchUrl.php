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
        . '(?<from>[A-Z0-9]{3})(?<depart>\d{6})(?<departspan>x[2-9])?'
        . '(?<to>[A-Z0-9]{3})(?<return>\d{6})?(?<returnspan>x[2-9])?'
        . '(?<cabin>[YWCF])(?<pax>\d{1,3})$#';

    private const int MAX_PASSENGERS = 9;

    /**
     * Days a flexible date may cover, itself included: `x3` is the named day
     * and the two after it.
     *
     * Three, because the cost is measured and it climbs with the window. One
     * 2-stop branch of one direction on a busy route goes 57 candidates and
     * 50ms at a single date to 184 and 92ms at three, and the real search runs
     * that shape once per destination airport and twice over for a round trip.
     * Seven days measured 510 and 224ms, which is a multi-second search once
     * multiplied out -- and enough rows to reach the 2,000 the candidate query
     * caps at, where what survives is whatever is cheapest and a whole
     * expensive day can vanish without saying so.
     *
     * Three lands where it was meant to. End to end on that route: a one-way
     * goes 103ms to 157ms, and a round trip flexible at both ends 129ms to
     * 238ms -- under twice the work for three times the days, because every
     * date predicate was already a range and a wider one is the same seek.
     */
    public const int MAX_SPAN = 3;

    /**
     * The separator for a span. Lowercase because the rest of the segment is
     * not: airport codes are `[A-Z0-9]` and the cabin is one of YWCF, so a
     * lowercase letter cannot be mistaken for either. A bare digit could --
     * `A39` is a real airport -- which is why a span cannot simply be appended.
     */
    private const string SPAN_MARK = 'x';

    /** Two digits is a century's worth, and this one is not running out. */
    private const int CENTURY = 2000;

    public function __construct(
        public string $from,
        public string $to,
        public string $depart,
        public ?string $return,
        public CabinClass $cabin = CabinClass::Economy,
        public int $adults = 1,
        public int $children = 0,
        public int $infants = 0,
        // Days each date covers, itself included. One is a single day, which is
        // what every search was before flexible dates and what most still are.
        public int $departSpan = 1,
        public int $returnSpan = 1,
    ) {}

    /** The last day the outbound may leave. */
    public function departUntil(): string
    {
        return self::plusDays($this->depart, $this->departSpan - 1);
    }

    /** The last day the return may leave, or null on a one-way. */
    public function returnUntil(): ?string
    {
        return $this->return === null ? null : self::plusDays($this->return, $this->returnSpan - 1);
    }

    /**
     * Read a path segment, or null when it is not one.
     *
     * Null rather than a partial object, so a caller can fall through to the
     * older query-string form instead of searching for something nobody asked
     * for. This is also the first format check `from`, `to` and `depart` have
     * ever had -- the query-string path validated none of them.
     */
    public static function parse(string $path): ?self
    {
        if (preg_match(self::PATTERN, trim($path), $match) !== 1) {
            return null;
        }

        $depart = self::readDate($match['depart']);
        $return = $match['return'] === '' ? null : self::readDate($match['return']);

        // A date the calendar does not have is not a search. Past dates are
        // read faithfully and simply find nothing, which is the honest answer
        // and what the query-string form has always done.
        if ($depart === null || ($match['return'] !== '' && $return === null)) {
            return null;
        }

        $departSpan = self::readSpan($match['departspan']);
        $returnSpan = self::readSpan($match['returnspan']);

        // A span the format can spell but the search will not run. Null rather
        // than a quiet clamp: `x9` asks for something specific, and answering a
        // three-day search instead would look like it worked.
        if ($departSpan === null || $returnSpan === null) {
            return null;
        }

        if (!self::returnsAfterDeparting($depart, $return)) {
            return null;
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
            departSpan: $departSpan,
            returnSpan: $returnSpan,
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

        // A return date is what makes it a round trip. An explicit one-way still
        // wins, because links shared from the old form carry `triptype=oneway`
        // alongside whatever return date its tab had left behind.
        //
        // tryFrom, not fromRequest: fromRequest answers Oneway for anything it
        // does not recognise, including nothing at all. That was safe while the
        // form always stated a trip type, and became a bug the moment it
        // stopped -- every round trip submitted arrived with no `triptype` and
        // had its return date discarded on the way in.
        $oneway = TripType::tryFrom((string) $query->nullableStr(
            (string) Config::get('search.form.input.triptype'),
        )) === TripType::Oneway;
        $return = $oneway
            ? null
            : self::validDate($query->str((string) Config::get('search.form.input.return_date')));

        // The same rule the path form enforces: a trip cannot come back before
        // it leaves. Unchecked here too until now.
        if (!self::returnsAfterDeparting($depart, $return)) {
            return null;
        }

        return new self(
            from: $from,
            to: $to,
            depart: $depart,
            return: $return,
            cabin: CabinClass::fromRequest($query->nullableStr((string) Config::get('search.form.input.class'))),
            adults: self::paxFrom($query, 'adults', 1),
            children: self::paxFrom($query, 'children', 0),
            infants: self::paxFrom($query, 'infants', 0),
            departSpan: self::spanFrom($query, 'depart_flex'),
            returnSpan: $return === null ? 1 : self::spanFrom($query, 'return_flex'),
        );
    }

    public function path(): string
    {
        return sprintf(
            '/search/%s%s%s%s%s%s%s%s',
            $this->from,
            self::shortDate($this->depart),
            self::spanMark($this->departSpan),
            $this->to,
            $this->return === null ? '' : self::shortDate($this->return),
            $this->return === null ? '' : self::spanMark($this->returnSpan),
            $this->cabin->code(),
            $this->passengers(),
        );
    }

    /**
     * Who the search is for. Falls back to a lone adult when the counts in the
     * URL do not describe a bookable party -- a hand-edited link should answer
     * a sensible search rather than nothing at all.
     */
    public function party(): Party
    {
        return Party::fromCounts($this->adults, $this->children, $this->infants) ?? new Party();
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
     * DDMMYY to a full date, or null when the calendar has no such day.
     *
     * checkdate rather than a DateTimeImmutable: the latter rolls 31 February
     * into March without complaint, which would quietly answer a different
     * search from the one the URL names.
     */
    private static function readDate(string $ddmmyy): ?string
    {
        $day = (int) substr($ddmmyy, 0, 2);
        $month = (int) substr($ddmmyy, 2, 2);
        $year = self::CENTURY + (int) substr($ddmmyy, 4, 2);

        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    private static function shortDate(string $date): string
    {
        return date('dmy', (int) strtotime($date));
    }

    /**
     * A span as the URL spells it, which for a single day is nothing at all.
     *
     * That is what keeps every link written before flexible dates byte
     * identical -- and the canonical redirect depends on it, since a path that
     * renders differently from the one requested bounces.
     */
    private static function spanMark(int $span): string
    {
        return $span > 1 ? self::SPAN_MARK . $span : '';
    }

    /**
     * A span from the query string, falling back to a single day.
     *
     * intWithin answers the default rather than the nearest bound, and that is
     * the right direction here: a span the search will not run becomes no span
     * at all, which searches fewer days and never more. The path form refuses
     * such a URL outright instead, because there the span was written down.
     */
    private static function spanFrom(Input $query, string $key): int
    {
        return $query->intWithin((string) Config::get('search.form.input.' . $key), 1, 1, self::MAX_SPAN);
    }

    /** A span from its marker, null when it asks for more days than are on offer. */
    private static function readSpan(string $mark): ?int
    {
        if ($mark === '') {
            return 1;
        }

        $span = (int) substr($mark, 1);

        return $span >= 2 && $span <= self::MAX_SPAN ? $span : null;
    }

    /**
     * Whether a return is on or after its departure.
     *
     * Nothing checked this before: /search/YUL151026LHR011025Y1 -- a return a
     * year before the outbound -- answered 200 and drew a results page. With
     * windows on both ends it matters more, because the two can now overlap in
     * ways a single pair of dates could not.
     */
    private static function returnsAfterDeparting(string $depart, ?string $return): bool
    {
        return $return === null || $return >= $depart;
    }

    private static function plusDays(string $date, int $days): string
    {
        return $days < 1 ? $date : date('Y-m-d', (int) strtotime($date . ' +' . $days . ' day'));
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
