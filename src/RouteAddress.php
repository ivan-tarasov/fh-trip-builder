<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * How a route is spelled in the address bar, and read back out of it.
 *
 * "/route/montreal-to-toronto". A class rather than another one-line helper
 * beside airportUrl() and airlineUrl(), because this is the one address on the
 * site that has to be *read* as well as written, and reading it is the whole
 * problem: there is no code on the end to look the city up by, so the names
 * have to identify it and the separator has to be found.
 *
 * The other four addresses carry a code because a place has one thing to be
 * named. A route is a pair, and naming both ends with codes spelled the pair
 * out twice -- "/route/coolangatta-gold-coast-ool/london-lon" is 44 characters
 * for one hop. This form is the phrase somebody actually types into a search
 * engine, which on a route page is the entire phrase.
 *
 * It is only a valid form while city names are unique, which is a fact about
 * the data and not something this can enforce: two cities sharing a name would
 * share an address and one of them would become unreachable. All 231 are
 * distinct today, and RouteAddressTest asserts it over the whole table so a
 * seed that broke it could not ship quietly.
 */
final class RouteAddress
{
    /**
     * What goes between the two halves.
     *
     * Hyphens either side, so it cannot be confused with the word "to" inside
     * a name: "Sao Tome" slugs to "sao-tome", which contains "-to" but not
     * "-to-". Checked against all 231 city names -- none contains the
     * separator, none ends in "-to" and none begins with "to-".
     */
    private const string JOIN = '-to-';

    /** Where a route's page lives. */
    public static function path(string $fromName, string $toName): string
    {
        return '/route/' . Helper::slug($fromName) . self::JOIN . Helper::slug($toName);
    }

    /**
     * The two city codes a slug names, or null when it names no one route.
     *
     * Every possible split is tried rather than the first one taken, and the
     * answer has to be the only one that resolves. A name holding the separator
     * would otherwise be read as the join -- and the failure would not be an
     * error, it would be a page about two different cities.
     *
     * Ambiguity therefore answers null, which the controller turns into a 404.
     * That is deliberate: serving one of two possible routes would be a page
     * quietly about the wrong pair, and a missing page is the better of those
     * two. Nothing in this data is ambiguous -- all 53,130 ordered pairs split
     * exactly one way, which is measured in the test rather than assumed here.
     *
     * @param array<string, string> $codesBySlug as index() returns it
     * @return array{string, string}|null [from, to]
     */
    public static function read(string $slug, array $codesBySlug): ?array
    {
        $slug = mb_strtolower($slug);
        $found = [];
        $offset = 0;

        while (($at = strpos($slug, self::JOIN, $offset)) !== false) {
            $from = substr($slug, 0, $at);
            $to = substr($slug, $at + strlen(self::JOIN));

            if (isset($codesBySlug[$from], $codesBySlug[$to])) {
                $found[] = [$codesBySlug[$from], $codesBySlug[$to]];
            }

            // One past the hyphen, not past the whole separator: a slug ending
            // "-to" followed by another "-to-" would hide the second one.
            $offset = $at + 1;
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The cities keyed by the way this address spells them.
     *
     * Built here rather than selected, because a slug is how this app spells a
     * name and not something the database should hold a second copy of -- the
     * same reason the city, country, airport and airline addresses are all
     * built in PHP. It is 231 string operations against a map the airport board
     * already asks for.
     *
     * @param array<string, string> $names code => name, as CityRepository::names() returns it
     * @return array<string, string> slug => code
     */
    public static function index(array $names): array
    {
        $bySlug = [];

        foreach ($names as $code => $name) {
            $bySlug[Helper::slug($name)] = (string) $code;
        }

        return $bySlug;
    }
}
