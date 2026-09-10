<?php

declare(strict_types=1);

namespace TripBuilder\View;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Http\Input;
use TripBuilder\SearchUrl;

/**
 * The searches this browser has run, offered back in the origin and destination
 * fields.
 *
 * Kept in a cookie, following saved flights (MyController::savedKeys()): there
 * are no accounts to hang a history on, and the `search` table cannot answer
 * this -- it is a global popularity counter keyed by a hash of the route and
 * dates, with no column saying who ran it.
 *
 * What is stored is the SearchUrl path, because a search identity already has a
 * compact spelling and this is it. That also gives the validator for free:
 * SearchUrl::parse() is the same parser the router trusts, and it rejects
 * everything malformed without a second regex to keep in step.
 */
final readonly class RecentSearches
{
    public const string COOKIE = 'tb_recent_searches';

    /** Enough to recognise last week's search, few enough to read at a glance. */
    private const int LIMIT = 6;

    private const int MAX_AGE = 60 * 60 * 24 * 365;

    /**
     * Where the last search left from, or null.
     *
     * The homepage opens its "From" field on this. No new cookie for it: a
     * search identity is already stored here and SearchUrl::parse() has
     * already validated it, so the origin is a field on something proven
     * rather than a second thing to keep in step.
     */
    public static function latestOrigin(Input $cookies): ?string
    {
        return (self::read($cookies)[0] ?? null)?->from;
    }

    /**
     * The stored searches, newest first, with anything unparseable dropped.
     *
     * @return list<SearchUrl>
     */
    public static function read(Input $cookies): array
    {
        $raw = json_decode($cookies->str(self::COOKIE), true);

        if (!is_array($raw)) {
            return [];
        }

        $searches = [];

        foreach ($raw as $path) {
            // Written by the browser, so it is input like any other. A hand
            // edited cookie gets no further than a path that does not parse.
            if (!is_string($path) || isset($searches[$path])) {
                continue;
            }

            $search = SearchUrl::parse($path);

            if ($search !== null) {
                $searches[$path] = $search;
            }

            if (count($searches) === self::LIMIT) {
                break;
            }
        }

        return array_values($searches);
    }

    /**
     * Put this search at the front, and send the cookie back.
     *
     * Called while the results are being built, which is before anything is
     * echoed -- index.php buffers output, so the header still goes out.
     */
    public static function remember(Input $cookies, SearchUrl $search, bool $secure): void
    {
        $stored = array_map(static fn(SearchUrl $s): string => $s->path(), self::read($cookies));

        setcookie(self::COOKIE, (string) json_encode(self::withNewest($stored, $search->path())), [
            'expires' => time() + self::MAX_AGE,
            'path' => '/',
            'samesite' => 'Lax',
            'secure' => $secure,
            // Read by PHP to render the field, and by nothing in the browser.
            'httponly' => true,
        ]);
    }

    /**
     * The stored list with this path at the front.
     *
     * Unique first, then cut: running the same search twice should move it up,
     * not push the oldest one off the end to make room for a duplicate.
     *
     * @param list<string> $stored
     * @return list<string>
     */
    public static function withNewest(array $stored, string $path): array
    {
        return array_slice(array_values(array_unique([$path, ...$stored])), 0, self::LIMIT);
    }

    /**
     * The stored searches as the form draws them.
     *
     * Named by city rather than by airport: "Toronto – Paris" is the trip, where
     * "Lester B. Pearson International – Paris" is one end of it spelled out and
     * the other not. Names come from the list the form is already rendering, so
     * a code with no name left in the network -- an airport since disabled --
     * falls back to the code rather than to an empty row.
     *
     * @param list<array<string, mixed>> $places
     *
     * @return list<array{
     *     path: string,
     *     from: string,
     *     to: string,
     *     when: string,
     *     parts: array{
     *         from: string,
     *         to: string,
     *         depart: string,
     *         return: string,
     *         depart_span: int,
     *         return_span: int,
     *         cabin: string,
     *         adults: int,
     *         children: int,
     *         infants: int
     *     }
     * }>
     */
    public static function rows(Input $cookies, array $places): array
    {
        $names = [];

        foreach ($places as $place) {
            $names[(string) $place['code']] = (string) ($place['city'] ?? $place['label']);
        }

        $rows = [];

        foreach (self::read($cookies) as $search) {
            $rows[] = [
                'path' => $search->path(),
                'from' => $names[$search->from] ?? $search->from,
                'to' => $names[$search->to] ?? $search->to,
                'when' => self::when($search->depart, $search->return),
                // The search taken apart, so choosing one can fill the form
                // rather than run it. Handed over in pieces because the browser
                // has no parser for a search path -- SearchUrl is the only
                // definition of that grammar, and a second one in JavaScript
                // would be a copy to keep in step.
                'parts' => [
                    'from' => $search->from,
                    'to' => $search->to,
                    'depart' => $search->depart,
                    'return' => $search->return ?? '',
                    'depart_span' => $search->departSpan,
                    'return_span' => $search->returnSpan,
                    'cabin' => $search->cabin->value,
                    'adults' => $search->adults,
                    'children' => $search->children,
                    'infants' => $search->infants,
                ],
            ];
        }

        return $rows;
    }

    /**
     * "15 Oct" for a one-way, "15 – 22 Oct" when both fall in one month, and
     * "28 Oct – 3 Nov" when they do not.
     */
    private static function when(string $depart, ?string $return): string
    {
        try {
            $out = new DateTimeImmutable($depart);
            $back = $return === null ? null : new DateTimeImmutable($return);
        } catch (Throwable) {
            return '';
        }

        if ($back === null) {
            return $out->format('j M');
        }

        return $out->format('M') === $back->format('M')
            ? sprintf('%s – %s', $out->format('j'), $back->format('j M'))
            : sprintf('%s – %s', $out->format('j M'), $back->format('j M'));
    }
}
