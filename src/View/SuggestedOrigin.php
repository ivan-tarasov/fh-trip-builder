<?php

declare(strict_types=1);

namespace TripBuilder\View;

/**
 * Which place the homepage opens its "From" field on.
 *
 * The field used to arrive empty, which is a fair default and a wasted one: a
 * visitor who searched from Montreal last week is going to search from
 * Montreal again, and the app already knows.
 *
 * The order is what makes this worth writing down rather than inlining.
 * Where somebody searched from beats where they appear to be, because it is a
 * statement they made rather than a guess about them -- somebody who lives in
 * Montreal and books from Toronto has said so, and a location lookup would
 * overrule them every visit. The guess is the cold start, for a first visit
 * with nothing to go on.
 *
 * Deliberately not clever beyond that: no counting of which origin was used
 * most, no weighting by recency. The most recent search is the one the visitor
 * is most likely still thinking about, and a field is cheap to change.
 *
 * Pure, and takes candidates rather than fetching them. What it costs to
 * decide is a comparison; what it would cost to *find* the candidates is a
 * cookie, a request header and a query, none of which belong in a rule about
 * precedence.
 */
final readonly class SuggestedOrigin
{
    /**
     * @param string|null $searchedBefore where the last search left from
     * @param string|null $whereTheyAre the nearest place to the visitor
     * @param list<string> $offered every code the picker can actually select
     */
    public static function choose(?string $searchedBefore, ?string $whereTheyAre, array $offered): ?string
    {
        foreach ([$searchedBefore, $whereTheyAre] as $candidate) {
            // Checked against the list rather than trusted. The first arrives
            // from a cookie, which is input like any other; the second from a
            // header a proxy sets. A code the picker does not offer would
            // render as an empty field anyway -- there is no option to select
            // -- so this is about saying no on purpose rather than by accident.
            //
            // No separate test for an empty string: nothing offered is '', so
            // the list refuses it already. There was one, and deleting it
            // changed no test -- which is the same unreachable-guard pattern
            // twice over on this branch.
            if ($candidate !== null && in_array($candidate, $offered, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
