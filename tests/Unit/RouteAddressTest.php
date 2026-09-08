<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\RouteAddress;

/**
 * "/route/montreal-to-toronto", written and read back.
 *
 * The only address on the site with no code in it, which is what makes reading
 * it a problem worth a test: every other page looks up the code off the end of
 * its slug, and this one has to find the separator and then identify two cities
 * by name alone.
 *
 * The hard case is a name that contains the separator. Nothing in this seed
 * does -- see the integration test beside this one, which asserts it over the
 * whole table -- so the cases below are constructed. They are the reason read()
 * tries every split instead of the first: taken at the first "-to-", a route
 * from Paris to a city called "Rome to Nice" would be read as Paris to Rome,
 * and the answer would not be an error, it would be a page about the wrong
 * pair.
 */
final class RouteAddressTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function routes(): array
    {
        return [
            'one word each' => ['Montreal', 'Toronto', '/route/montreal-to-toronto'],
            'two words' => ['New York', 'London', '/route/new-york-to-london'],
            'hyphens on both sides' => [
                'Tel Aviv-Yafo',
                'Coolangatta (Gold Coast)',
                '/route/tel-aviv-yafo-to-coolangatta-gold-coast',
            ],
            // The fold that the airport titles needed, reached from here too.
            'an accent' => ['Malmö', 'Zürich', '/route/malmo-to-zurich'],
        ];
    }

    #[DataProvider('routes')]
    public function testARouteIsSpelledFromItsTwoNames(string $from, string $to, string $path): void
    {
        self::assertSame($path, RouteAddress::path($from, $to));
    }

    #[DataProvider('routes')]
    public function testASpelledRouteReadsBackToItsCities(string $from, string $to, string $path): void
    {
        $index = RouteAddress::index(['AAA' => $from, 'BBB' => $to]);

        self::assertSame(
            ['AAA', 'BBB'],
            RouteAddress::read(substr($path, strlen('/route/')), $index),
        );
    }

    /**
     * The whole reason every split is tried.
     *
     * "paris-to-rome-to-nice" splits two ways, and only one of them is a route
     * anybody meant -- but which one depends entirely on what cities exist.
     * Here only the second reading resolves, so taking the first "-to-" would
     * have answered 404 for a route that does exist.
     */
    public function testASeparatorInsideANameIsNotMistakenForTheJoin(): void
    {
        $index = RouteAddress::index(['PAR' => 'Paris', 'RTN' => 'Rome to Nice']);

        self::assertSame(['PAR', 'RTN'], RouteAddress::read('paris-to-rome-to-nice', $index));
    }

    /**
     * And the reason it insists on exactly one.
     *
     * With all four names in the table the same slug reads two ways, and
     * neither is more correct than the other. Null, so the controller answers
     * 404: a page about one of two possible pairs would be quietly wrong, and
     * a missing page is the better of those two.
     */
    public function testASlugThatReadsTwoWaysNamesNoRoute(): void
    {
        $index = RouteAddress::index([
            'PAR' => 'Paris',
            'RTN' => 'Rome to Nice',
            'PTR' => 'Paris to Rome',
            'NCE' => 'Nice',
        ]);

        self::assertNull(RouteAddress::read('paris-to-rome-to-nice', $index));
    }

    /**
     * A slug is read as written, so a canonical redirect has something to
     * redirect.
     */
    public function testCaseIsFoldedForTheLookupAndNotForTheAddress(): void
    {
        $index = RouteAddress::index(['YMQ' => 'Montreal', 'YTO' => 'Toronto']);

        self::assertSame(['YMQ', 'YTO'], RouteAddress::read('MONTREAL-TO-TORONTO', $index));
        self::assertSame(['YMQ', 'YTO'], RouteAddress::read('Montreal-To-Toronto', $index));

        self::assertNotSame('/route/MONTREAL-TO-TORONTO', RouteAddress::path('Montreal', 'Toronto'));
    }

    /** @return array<string, array{string}> */
    public static function notRoutes(): array
    {
        return [
            'no separator' => ['montreal-toronto'],
            'a city we do not sell on the left' => ['nowhere-to-toronto'],
            'and on the right' => ['montreal-to-nowhere'],
            'neither' => ['nowhere-to-nowhere-else'],
            'the separator alone' => ['-to-'],
            'nothing at all' => [''],
            'only one city' => ['montreal'],
            // The old spellings, which shipped for one commit each. Neither
            // names a route now, and both should say so rather than half-match.
            'the two-slug form' => ['montreal-ymq'],
            'the code form' => ['ymq'],
        ];
    }

    #[DataProvider('notRoutes')]
    public function testAnythingElseNamesNoRoute(string $slug): void
    {
        $index = RouteAddress::index(['YMQ' => 'Montreal', 'YTO' => 'Toronto']);

        self::assertNull(RouteAddress::read($slug, $index));
    }

    /**
     * The index is keyed the way the address spells a name, which is the only
     * thing that makes the two halves agree.
     */
    public function testTheIndexIsKeyedBySlug(): void
    {
        self::assertSame(
            ['new-york' => 'NYC', 'tel-aviv-yafo' => 'TLV', 'malmo' => 'MMX'],
            RouteAddress::index(['NYC' => 'New York', 'TLV' => 'Tel Aviv-Yafo', 'MMX' => 'Malmö']),
        );
    }
}
