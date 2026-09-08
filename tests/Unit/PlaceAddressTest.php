<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;
use TripBuilder\Routes;

/**
 * How a city and a country are spelled in a URL, and read back out of one.
 *
 * The router matches a place path on shape alone -- /city/anything-hyphenated
 * -- so what actually decides whether a page exists is the code read off the
 * end of the slug. Two rules, one length apart: three characters for a city's
 * IATA code, two for a country's ISO one.
 *
 * Worth its own test because the first spelling of the city rule allowed
 * exactly one word before the code. Montreal worked, which hid it, and every
 * city whose name has two -- Tel Aviv-Yafo, Coolangatta (Gold Coast) -- was a
 * 404. The country rule was written from the fixed one and inherits both the
 * behaviour and the risk of drifting from it.
 */
final class PlaceAddressTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function cities(): array
    {
        return [
            'one word' => ['Montreal', 'YMQ', 'montreal-ymq'],
            'two words' => ['Tel Aviv-Yafo', 'TLV', 'tel-aviv-yafo-tlv'],
            'a name in brackets' => ['Coolangatta (Gold Coast)', 'OOL', 'coolangatta-gold-coast-ool'],
            'a digit in the code' => ['Nowhere', 'A39', 'nowhere-a39'],
        ];
    }

    #[DataProvider('cities')]
    public function testACityGoesToASlugAndComesBack(string $name, string $code, string $slug): void
    {
        self::assertSame($slug, Helper::placeSlug($name, $code));
        self::assertSame($code, Helper::placeCode($slug, 3));
    }

    /** @return array<string, array{string, string, string}> */
    public static function countries(): array
    {
        return [
            'one word' => ['Canada', 'CA', 'canada-ca'],
            'two words' => ['United States', 'US', 'united-states-us'],
            // The one non-ASCII name in the seed, and its apostrophe is the
            // curly one. slug() has no /u flag and does not need it: the bytes
            // are not [a-z0-9], so they collapse to a hyphen like any other
            // punctuation.
            'a curly apostrophe' => ['Cote d’Ivoire', 'CI', 'cote-d-ivoire-ci'],
        ];
    }

    #[DataProvider('countries')]
    public function testACountryGoesToASlugAndComesBack(string $name, string $code, string $slug): void
    {
        self::assertSame($slug, Helper::placeSlug($name, $code));
        self::assertSame($code, Helper::placeCode($slug, 2));
    }

    /**
     * A slug is read as written, so a canonical redirect has something to
     * redirect. Lower-casing it in the lookup and returning the code upper-case
     * is what lets /city/LONDON-LON find London and still be sent to its one
     * address.
     */
    public function testCaseIsFoldedForTheLookupAndNotForTheAddress(): void
    {
        self::assertSame('LON', Helper::placeCode('LONDON-LON', 3));
        self::assertSame('CA', Helper::placeCode('Canada-CA', 2));

        self::assertNotSame('LONDON-LON', Helper::placeSlug('London', 'LON'));
        self::assertSame('london-lon', Helper::placeSlug('London', 'LON'));
    }

    /** @return array<string, array{string, int}> */
    public static function notPlaces(): array
    {
        return [
            'a code and no name' => ['ymq', 3],
            'a name and no code' => ['montreal', 3],
            'a country code where a city code belongs' => ['canada-ca', 3],
            'a city code where a country code belongs' => ['montreal-ymq', 2],
            'a trailing hyphen' => ['montreal-', 3],
            'a leading hyphen' => ['-ymq', 3],
            'nothing at all' => ['', 3],
            'a path, not a slug' => ['city/montreal-ymq', 3],
            'punctuation' => ['montreal.ymq', 3],
        ];
    }

    #[DataProvider('notPlaces')]
    public function testAnythingElseNamesNoPlace(string $slug, int $length): void
    {
        self::assertNull(Helper::placeCode($slug, $length));
    }

    /**
     * The router is deliberately loose, which is why the check above exists at
     * all. If it ever tightens, the guard in the controllers becomes dead code
     * -- worth being told rather than quietly keeping both.
     */
    public function testTheRouterAcceptsWhatTheControllersHaveToTurnAway(): void
    {
        self::assertSame('City@show', Routes::resolve('/city/nonsense'));
        self::assertSame('Country@show', Routes::resolve('/country/nonsense'));

        self::assertNull(Helper::placeCode('nonsense', 3));
        self::assertNull(Helper::placeCode('nonsense', 2));
    }

    /**
     * The two indexes, which are ordinary fixed routes and the only way in to
     * the pages above.
     */
    public function testEachDirectoryIsARoute(): void
    {
        self::assertSame('City@index', Routes::resolve('/cities'));
        self::assertSame('Country@index', Routes::resolve('/countries'));

        // Public, so the sitemap lists them and no robots tag keeps them out.
        self::assertTrue(Routes::isPublic('/cities'));
        self::assertTrue(Routes::isPublic('/countries'));
    }
}
