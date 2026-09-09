<?php

declare(strict_types=1);

namespace TripBuilder\View;

/**
 * A long list of named places, arranged so somebody can find one in it.
 *
 * Cities today and countries next, which is why this is not part of either:
 * both are a few hundred rows of "name, code, address", and both are read the
 * same way -- you arrive knowing the name you want. Grouping by first letter is
 * what makes that a glance instead of a scroll, and an alphabet of the letters
 * actually present is what makes the groups navigable.
 *
 * Pure -- a list in, a list out. No request, no database, no config.
 */
final class Directory
{
    /**
     * Anything whose name does not begin with a letter.
     *
     * There is nothing like that in the data today. It is here because a
     * directory that silently drops a row is worse than one with an odd
     * heading, and "Île-de-France" or "'s-Hertogenbosch" would otherwise land
     * under a key nobody can click.
     */
    private const string OTHER = '#';

    /**
     * The same items, grouped under the letter they start with.
     *
     * Order is the caller's. The query already sorts by name, so each group
     * comes out sorted and the groups come out in alphabetical order without
     * this having to sort anything itself -- which also means a caller that
     * wants a different order inside a group can have one.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byLetter(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $groups[self::letterOf((string) $item['name'])][] = $item;
        }

        return $groups;
    }

    /**
     * The letter a name files under.
     *
     * mb_substr, not substr: a multi-byte first character would otherwise be
     * cut in half and produce a key that is not a letter in any alphabet.
     */
    private static function letterOf(string $name): string
    {
        $first = mb_strtoupper(mb_substr(trim($name), 0, 1));

        return preg_match('/^\p{L}$/u', $first) === 1 ? $first : self::OTHER;
    }
}
