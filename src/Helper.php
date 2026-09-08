<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTime;
use DateTimeZone;
use Exception;

class Helper
{
    /**
     * Return the project root directory
     */
    public static function getRootDir(): string
    {
        return dirname(__FILE__, 2);
    }

    public static function getUTCTime(int|float $offset): string
    {
        $hours = floor(abs($offset));
        $minutes = abs($offset) * 60 % 60;

        $sign = ($offset >= 0) ? '+' : '-';

        return sprintf("GMT%s%02d:%02d", $sign, $hours, $minutes);
    }


    public static function getGitRepo(string $source = 'github'): string
    {
        $url = Config::get("git.$source.url");
        $user = Config::get("git.$source.user");
        $repo = Config::get("git.$source.repo");

        return "https://$url/$user/$repo";
    }

    /**
     * @throws Exception
     */
    public static function getGitInfo(): array
    {
        // Four exec() shell-outs feed the footer on every page render — about
        // 200ms of process spawning. The answer only changes when the checkout
        // does, so it is cached to disk and refreshed when git's own state
        // changes; the static keeps it to one read per request.
        static $info = null;

        if ($info !== null) {
            return $info;
        }

        $cacheFile = self::getRootDir() . '/cache/git-info.json';
        $stamp = self::gitStamp();

        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);

            if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp && isset($cached['info'])) {
                return $info = $cached['info'];
            }
        }

        $git_commitDate = new DateTime(trim(exec('git log -n1 --pretty=%ci HEAD')));
        $git_commitDate->setTimezone(new DateTimeZone('UTC'));

        $info = [
            'branch' => trim(exec('git rev-parse --abbrev-ref HEAD')),
            'tag' => trim(exec('git describe --tags --abbrev=0')),
            'commit_hash' => trim(exec('git log --pretty="%h" -n1 HEAD')),
            'commit_date' => $git_commitDate->format('Y-m-d H:i:s'),
        ];

        // A failure to cache only costs speed, never correctness.
        @file_put_contents($cacheFile, json_encode(['stamp' => $stamp, 'info' => $info]));

        return $info;
    }

    /**
     * A fingerprint of the checkout's current position: HEAD's contents plus
     * the modification time of the ref it points at, so committing, switching
     * branches or checking out all invalidate the cached git info.
     */
    private static function gitStamp(): string
    {
        $head = self::getRootDir() . '/.git/HEAD';

        if (!is_file($head)) {
            return 'no-git';
        }

        $contents = trim((string) file_get_contents($head));
        $stamp = $contents . ':' . filemtime($head);

        if (str_starts_with($contents, 'ref: ')) {
            $ref = self::getRootDir() . '/.git/' . trim(substr($contents, 5));

            if (is_file($ref)) {
                $stamp .= ':' . filemtime($ref);
            }
        }

        return $stamp;
    }

    /**
     * Return random number from provided range
     */
    public static function random(array $range): int
    {
        return rand($range[0], $range[1]);
    }

    /**
     * Sample an index from a cumulative weight table (binary search).
     *
     * `$cumulative[$i]` is the running total up to and including entry `$i`, so
     * the table is ascending and the last value is `$total`. An entry's chance
     * is its own share of that total, which is what lets a caller weight a draw
     * by anything it can express as a number -- route traffic, fare brand mix,
     * how well an aircraft fits a leg.
     *
     * @param list<float> $cumulative ascending running totals
     */
    public static function pickWeighted(array $cumulative, float $total): int
    {
        $target = mt_rand() / mt_getrandmax() * $total;

        $low = 0;
        $high = count($cumulative) - 1;

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if ($cumulative[$mid] < $target) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    public static function plural(
        int $number,
        string $singular,
        ?string $plural = null,
        bool $showNumber = false,
    ): string {
        $word = $number === 1 ? $singular : ($plural ?? "{$singular}s");
        return $showNumber ? "$number $word" : $word;
    }

    public static function bookingIdToString(int $id): string
    {
        return number_format($id, 0, '', '-');
    }

    /**
     * The card scheme a number belongs to, from the digits that identify it.
     *
     * Issuer ranges, not lengths: the first one or two digits settle it, which
     * is why a form can name the scheme before the number is finished. Mirrored
     * by SCHEMES in global.js, which also needs the grouping.
     */
    public static function cardScheme(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return match (true) {
            str_starts_with($digits, '4') => 'Visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $digits) => 'Mastercard',
            (bool) preg_match('/^3[47]/', $digits) => 'Amex',
            (bool) preg_match('/^6(011|5|4[4-9])/', $digits) => 'Discover',
            default => 'Card',
        };
    }

    /**
     * Whether a card number's check digit agrees with the rest of it.
     *
     * Catches a mistyped or transposed digit before anything is submitted. It
     * says nothing about whether the card exists or has money on it — only a
     * gateway can answer that.
     */
    public static function isLuhnValid(string $number): bool
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if (preg_match('/^\d{13,19}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $value = (int) $digits[$i];

            if ($double) {
                $value *= 2;

                if ($value > 9) {
                    $value -= 9;
                }
            }

            $sum += $value;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    public static function hoursAndMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $rest . 'm';
        }

        return $rest === 0 ? $hours . 'h' : sprintf('%dh %dm', $hours, $rest);
    }

    /**
     * What a range control's pill reads, naming the end that is actually set.
     *
     * On two handles the end matters: a floor that has been dragged up has to
     * say "From", or the same number would mean two opposite things. A `null`
     * ceiling is a single-handled control, which sits under a label that
     * already says which end it is, so the pill carries the number alone.
     * Mirrored by sliderCaption() in global.js, which repaints the pill as a
     * handle moves.
     */
    public static function sliderCaption(string $kind, int $from, ?int $to, int $min, int $max): string
    {
        $show = static fn(int $value): string => $kind === 'money'
            ? '$' . number_format($value)
            : self::hoursAndMinutes($value);

        if ($to === null) {
            return $show($from);
        }

        if ($from > $min && $to < $max) {
            return sprintf('From %s to %s', $show($from), $show($to));
        }

        return $from > $min ? 'From ' . $show($from) : 'Up to ' . $show($to);
    }

    /**
     * The scale a filter slider draws on: a readable step, and the ends snapped
     * onto it.
     *
     * Which way the bottom end rounds depends on what the handle there means.
     * A single handle is a ceiling, and a ceiling rounded *down* lands under
     * the cheapest thing on offer — "up to $6,000" over a $6,042 flight
     * matches nothing, and the far left of the track is the one place a
     * visitor is certain to drag to. A range has a floor down there instead,
     * and a floor under everything excludes nothing, so that one rounds down
     * and keeps the true spread visible.
     *
     * @param list<int> $steps allowed step sizes, smallest first
     * @param int $stops roughly how many positions the handle should have
     * @return array{min: int, max: int, step: int}
     */
    public static function sliderScale(
        int $low,
        int $high,
        array $steps,
        int $stops,
        bool $ceilingOnly,
    ): array {
        $step = self::sliderStep(max(1, $high - $low), $steps, $stops);

        $min = $ceilingOnly
            ? (int) ceil($low / $step) * $step
            : (int) floor($low / $step) * $step;
        $max = (int) ceil($high / $step) * $step;

        // One step wide at worst, so the track is draggable rather than a dot.
        return ['min' => $min, 'max' => max($max, $min + $step), 'step' => $step];
    }

    /**
     * The smallest allowed step that keeps the handle to roughly $stops
     * positions, so a slider is neither jumpy nor pixel-precise.
     *
     * @param list<int> $steps
     */
    public static function sliderStep(int $span, array $steps, int $stops): int
    {
        $wanted = max(1, (int) round($span / $stops));

        foreach ($steps as $step) {
            if ($wanted <= $step) {
                return $step;
            }
        }

        return $steps[count($steps) - 1];
    }

    /**
     * An airport's name with the city it is in stripped off the front.
     *
     * Where the two are shown together, "Amsterdam" above "Amsterdam Airport
     * Schiphol" stutters and costs a line of wrapping. Only a whole leading
     * word goes, or a city called "San" would maul "Santiago"; a name that is
     * nothing but the city leaves nothing behind, and no city strips nothing.
     */
    public static function airportNameAfterCity(string $title, string $city): string
    {
        if ($city === '') {
            return $title;
        }

        if (strcasecmp($title, $city) === 0) {
            return '';
        }

        $prefix = $city . ' ';

        return strncasecmp($title, $prefix, strlen($prefix)) === 0
            ? substr($title, strlen($prefix))
            : $title;
    }

    /**
     * A date, carrying its year only when that year is not the current one.
     *
     * Every date on a flight was printed as "Thu, 15 Oct". Inside one year that
     * is the right amount to say; across a new year it is a guess. A trip
     * booked in December 2026 that leaves in January 2027 showed a departure
     * indistinguishable from one eleven months earlier, and the pages that
     * show a whole itinerary -- search results, the booking card, the booking
     * itself -- all read from here.
     *
     * The two formats are passed rather than a year appended, because where the
     * year goes depends on the shape of the rest: "Thu, 15 Oct 2027" wants a
     * space and "Wed, December 30, 2027" wants a comma.
     *
     * Against today by default: the question a reader is answering is "which
     * year is this", and today is what they are standing in. A caller can pass
     * something else to compare against -- the far end of a date range names
     * its own year, so the near end only needs one when the two disagree.
     */
    /**
     * An hours-from-UTC offset, written the way a clock is read.
     *
     * The column is decimal(4,2) and it means it: eight of our airports sit at
     * +5.5, Kathmandu at +5.75 and Adelaide at +10.5. Printing the number
     * straight gives "GMT+5.5", and rounding it to an hour tells everyone
     * flying to India the wrong time. Minutes are only shown when there are
     * any, so the common case stays "GMT+3" rather than "GMT+3:00".
     */
    public static function gmtOffset(float $hours): string
    {
        if ($hours === 0.0) {
            return 'GMT';
        }

        $minutes = (int) round(abs($hours) * 60);
        $whole = intdiv($minutes, 60);
        $part = $minutes % 60;

        return sprintf(
            'GMT%s%d%s',
            $hours < 0 ? '-' : '+',
            $whole,
            $part === 0 ? '' : sprintf(':%02d', $part),
        );
    }

    /**
     * Accented Latin letters and the plain ones they stand in for.
     *
     * Latin-1 Supplement and Latin Extended-A, which is what this data holds
     * and what a European or Latin American place name is written with. Not
     * ext-intl: the CI image installs mysqli, pdo_mysql, curl and mbstring and
     * nothing else, so Transliterator is not there to be called. Not iconv's
     * //TRANSLIT either -- on macOS it renders Montréal as "Montr'eal", which
     * is worse than doing nothing.
     *
     * Written as two strings rather than a map because that is how it stays
     * legible and how strtr() takes it fastest: each character in the first
     * becomes the one at the same position in the second.
     */
    private const string ACCENTED =
        'ÀÁÂÃÄÅàáâãäåÈÉÊËèéêëÌÍÎÏìíîïÒÓÔÕÖØòóôõöøÙÚÛÜùúûüÝýÿÑñÇçÐðŠšŽžŸ';
    private const string PLAIN =
        'AAAAAAaaaaaaEEEEeeeeIIIIiiiiOOOOOOooooooUUUUuuuuYyyNnCcDdSsZzY';

    /**
     * The few that are two letters rather than an accent on one.
     *
     * Kept apart because the fold above is positional and cannot expand: a
     * ligature stands for a pair, and Æ folded to "A" loses half of it. None of
     * these is in this seed; they are here because this map is the sort of
     * thing that gets copied to the next project.
     */
    private const array LIGATURES = [
        'Æ' => 'AE', 'æ' => 'ae',
        'Œ' => 'OE', 'œ' => 'oe',
        'Þ' => 'Th', 'þ' => 'th',
        'ß' => 'ss',
    ];

    /**
     * A name as it appears in a URL: lower case, words joined by hyphens.
     *
     * Accents are folded first, and they have to be. Every one of the 231 major
     * city names in this seed is ASCII, which is why this did not fold anything
     * for a long time -- but airport titles are not, and eight of them are not.
     * Without the fold, `[^a-z0-9]` reads an accented letter as punctuation and
     * leaves a hyphen where it stood: Cancún International addressed itself as
     * "canc-n-international-cun" and Dakar's as
     * "dakar-yoff-l-opold-s-dar-senghor-international-dkr".
     *
     * Anything the fold does not know is still dropped, and that is still not
     * broken -- a place is resolved by the code on the end of its slug, and the
     * canonical redirect rewrites the name half to whatever this returns. It is
     * only ugly, which for a URL somebody reads is reason enough to fold.
     */
    public static function slug(string $text): string
    {
        $folded = strtr($text, self::foldMap());
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($folded));

        return trim($slug ?? '', '-');
    }

    /**
     * The fold as strtr() wants it, built once per process.
     *
     * Multi-byte, so the two constants cannot be walked a byte at a time --
     * ß is two bytes and every accented letter here is at least two. strtr()
     * with an array handles characters of any length; with two strings it would
     * pair bytes and produce mojibake.
     *
     * @return array<string, string>
     */
    private static function foldMap(): array
    {
        static $map = null;

        if ($map === null) {
            $map = array_combine(
                mb_str_split(self::ACCENTED),
                mb_str_split(self::PLAIN),
            ) + self::LIGATURES;
        }

        return $map;
    }

    /**
     * "montreal-ymq" -- the name for a reader, the code for the lookup.
     *
     * Not city-specific: a country, an airport and an airline are all a name
     * with a code, and spelling their addresses two different ways would be a
     * second rule to remember for no gain.
     */
    public static function placeSlug(string $name, string $code): string
    {
        return self::slug($name) . '-' . mb_strtolower($code);
    }

    /**
     * Where an airport's page lives.
     *
     * The whole path and not just the slug, because four callers were spelling
     * "/airport/" in front of one -- two controllers, the sitemap and now the
     * blocks that list a city's and a country's airports. A fifth would have
     * been a fifth place to change the day the prefix does.
     */
    public static function airportUrl(string $title, string $code): string
    {
        return '/airport/' . self::placeSlug($title, $code);
    }

    /** Where an airline's page lives. */
    public static function airlineUrl(string $name, string $code): string
    {
        return '/airline/' . self::placeSlug($name, $code);
    }

    /**
     * The code off the end of a place slug, or null when there is not one.
     *
     * The inverse of placeSlug(), and read from the end rather than the start,
     * because a name can hold as many hyphens as it likes -- "tel-aviv-yafo",
     * "coolangatta-gold-coast" and "cote-d-ivoire" are all ours. The first
     * spelling of this allowed exactly one word, which turned away every city
     * whose name has two while montreal-ymq worked and hid it.
     *
     * The length is the caller's, and it is the whole difference between a
     * city's address and a country's: three characters for IATA, two for ISO.
     * Anything else is not a mistyped place, it is not a place.
     */
    public static function placeCode(string $slug, int $length): ?string
    {
        $slug = mb_strtolower($slug);

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*-[a-z0-9]{' . $length . '}$/', $slug) === 1
            ? strtoupper(substr($slug, -$length))
            : null;
    }

    public static function dateLabel(
        string|int $when,
        string $format,
        string $formatWithYear,
        string|int|null $reference = null,
    ): string {
        $timestamp = self::stamp($when);
        $against = $reference === null ? time() : self::stamp($reference);

        return date(
            date('Y', $timestamp) === date('Y', $against) ? $format : $formatWithYear,
            $timestamp,
        );
    }

    private static function stamp(string|int $when): int
    {
        return is_int($when) ? $when : (int) strtotime($when);
    }
}
