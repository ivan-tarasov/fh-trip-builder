<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

use TripBuilder\Helper;
use TripBuilder\Repository\CityRepository;
use TripBuilder\RouteAddress;

/**
 * The three facts about the data that "/route/montreal-to-toronto" rests on.
 *
 * The unit test beside this one covers the reading, and it can only cover the
 * cases it constructs. This covers the ones that decide whether the address
 * form is usable at all, and they are not facts about the code:
 *
 * 1. City names are unique. Two cities sharing one would share an address, and
 *    the second would be unreachable -- with no error anywhere, because the
 *    index is a map and the later key simply wins.
 * 2. No name contains the separator, so the obvious reading is the only one.
 * 3. No pair of names reads two ways, which is the same thing stated over every
 *    pair rather than every name -- and it is the one that would actually take
 *    a page away, since read() answers null rather than guess.
 *
 * All three hold today. They are asserted rather than trusted because the thing
 * that would break them is a seed edit: one new city called "Sao Tome to
 * Principe", or a second London, and a route page would quietly go missing.
 */
final class RouteAddressDataTest extends IntegrationTestCase
{
    /**
     * A name each, or the address form does not work.
     *
     * Asserted against the count of cities rather than against 231, so the
     * test is about uniqueness and not about how many cities the seed happens
     * to hold.
     */
    public function testEveryCityHasAnAddressOfItsOwn(): void
    {
        $names = $this->names();
        $index = RouteAddress::index($names);

        self::assertNotEmpty($names);
        self::assertCount(
            count($names),
            $index,
            'two cities share a slug, so one of them has no route address',
        );
    }

    /**
     * No name may hold the separator, or hold half of it at either end.
     *
     * The ends matter as much as the middle: a city slugging to "porto-to"
     * followed by the join would produce "porto-to-to-lisbon", and a city
     * called "To Lisbon" the mirror image.
     */
    public function testNoCityNameCanBeMistakenForTheJoin(): void
    {
        foreach (array_keys(RouteAddress::index($this->names())) as $slug) {
            self::assertStringNotContainsString('-to-', $slug, $slug . ' contains the separator');
            self::assertStringEndsNotWith('-to', $slug, $slug . ' ends in the separator');
            self::assertStringStartsNotWith('to-', $slug, $slug . ' starts with the separator');
        }
    }

    /**
     * And every ordered pair has to read exactly one way.
     *
     * Every pair, not a sample: this is the assertion that the form is safe,
     * and it costs a fraction of a second because it is string work over a map
     * already in memory. It also round-trips each pair through path() and
     * read(), so what is checked is the spelling this app writes rather than
     * one composed in the test.
     */
    public function testEveryPairOfCitiesReadsBackAsItself(): void
    {
        $names = $this->names();
        $index = RouteAddress::index($names);
        $slugs = [];

        foreach ($names as $code => $name) {
            $slugs[(string) $code] = Helper::slug($name);
        }

        $checked = 0;

        foreach ($names as $from => $fromName) {
            foreach ($names as $to => $toName) {
                if ($from === $to) {
                    continue;
                }

                $path = RouteAddress::path((string) $fromName, (string) $toName);
                $read = RouteAddress::read(substr($path, strlen('/route/')), $index);
                $checked++;

                self::assertSame(
                    [(string) $from, (string) $to],
                    $read,
                    sprintf(
                        '%s does not read back as %s to %s',
                        $path,
                        $slugs[(string) $from],
                        $slugs[(string) $to],
                    ),
                );
            }
        }

        // A guard on the guard: a names() that came back with one row would
        // pass every assertion above by never entering the loop.
        self::assertGreaterThan(10000, $checked, 'the pairs should number in the tens of thousands');
    }

    /**
     * @return array<string, string>
     */
    private function names(): array
    {
        return new CityRepository($this->connection())->names();
    }
}
