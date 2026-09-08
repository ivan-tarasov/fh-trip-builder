<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;
use TripBuilder\Routes;

/**
 * How a place is spelled in a URL, and read back out of one.
 *
 * Four kinds now: a city, a country, an airport and an airline. The router
 * matches a place path on shape alone -- /city/anything-hyphenated -- so what
 * actually decides whether a page exists is the code read off the end of the
 * slug. Two rules, one length apart: three characters for the IATA code of a
 * city or an airport, two for a country's ISO code or an airline's IATA one.
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
     * An airline's code is two characters like a country's, and unlike a
     * country's it is very often not two letters.
     *
     * Ten of the 105 we sell have a digit in the code and two have an accent in
     * the name, which is why these are real rows rather than invented ones: a
     * rule written against AC and BA would pass every test and 404 easyJet.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function airlines(): array
    {
        return [
            'two words' => ['Air Canada', 'AC', 'air-canada-ac'],
            'a letter then a digit' => ['Aegean', 'A3', 'aegean-a3'],
            'a digit then a letter' => ['IndiGo Airlines', '6E', 'indigo-airlines-6e'],
            'a name with inner capitals' => ['easyJet', 'U2', 'easyjet-u2'],
            'an accent in the name' => ['AeroMéxico', 'AM', 'aeromexico-am'],
            'both at once' => ['Gol Transportes Aéreos', 'G3', 'gol-transportes-aereos-g3'],
        ];
    }

    #[DataProvider('airlines')]
    public function testAnAirlineGoesToASlugAndComesBack(string $name, string $code, string $slug): void
    {
        self::assertSame($slug, Helper::placeSlug($name, $code));
        self::assertSame($code, Helper::placeCode($slug, 2));
        self::assertSame('/airline/' . $slug, Helper::airlineUrl($name, $code));
    }

    /**
     * Accents are folded, not dropped.
     *
     * The reason this exists: city and country names in this seed are all
     * ASCII, so slug() dropped accented letters for a long time and nothing
     * showed it. Airport titles are not all ASCII, and three of them addressed
     * themselves as nonsense -- "canc-n-international-cun" -- because
     * `[^a-z0-9]` reads an accented letter as punctuation.
     *
     * @return array<string, array{string, string}>
     */
    public static function accentedNames(): array
    {
        return [
            'an acute' => ['Cancún International', 'cancun-international'],
            'an umlaut' => ['Düsseldorf International Airport', 'dusseldorf-international-airport'],
            'several, and hyphens too' => [
                'Dakar-Yoff-Léopold Sédar Senghor International',
                'dakar-yoff-leopold-sedar-senghor-international',
            ],
            'a slashed o' => ['Ålesund Ørsta', 'alesund-orsta'],
            'a cedilla and a tilde' => ['São Paulo Guarulhos', 'sao-paulo-guarulhos'],
            // Not accents, and already correct before the fold existed: the
            // apostrophe and the en-dash are punctuation and become a hyphen.
            'a curly apostrophe' => ['Chicago O’hare International', 'chicago-o-hare-international'],
            'an en-dash' => ['Fort Lauderdale–Hollywood International', 'fort-lauderdale-hollywood-international'],
        ];
    }

    #[DataProvider('accentedNames')]
    public function testAnAccentFoldsToItsPlainLetter(string $name, string $slug): void
    {
        self::assertSame($slug, Helper::slug($name));
    }

    /**
     * A ligature stands for two letters, so it folds to two.
     *
     * None of these is in the seed. They are covered because the fold above is
     * positional and cannot expand, which is exactly the sort of thing that
     * looks fine until one arrives -- Æ folded to "A" would lose half of it.
     */
    public function testALigatureFoldsToBothItsLetters(): void
    {
        self::assertSame('aeroport', Helper::slug('Æroport'));
        self::assertSame('oeuvre', Helper::slug('œuvre'));
        self::assertSame('strasse', Helper::slug('Straße'));
        self::assertSame('thing', Helper::slug('Þing'));
    }

    /**
     * The fold must not have moved an address that already exists.
     *
     * 231 city pages and 93 country pages are already linked, sitemapped and
     * (in five cases) written into the footer by hand. All of those names are
     * ASCII, so the fold has nothing to do to them -- and this is the assertion
     * that says so, because a fold that quietly respelled one would 301 every
     * old link to a page that no longer answers at it.
     */
    public function testTheFoldLeavesAnAsciiNameAlone(): void
    {
        foreach (['Montreal', 'Tel Aviv-Yafo', 'Coolangatta (Gold Coast)', 'United States', 'Canada'] as $name) {
            self::assertSame(
                trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-'),
                Helper::slug($name),
                $name . ' should be spelled exactly as it was before the fold',
            );
        }
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
        self::assertSame('Airport@show', Routes::resolve('/airport/nonsense'));
        self::assertSame('Airline@show', Routes::resolve('/airline/nonsense'));

        self::assertNull(Helper::placeCode('nonsense', 3));
        self::assertNull(Helper::placeCode('nonsense', 2));

        // The two that have already been written into config by hand: an
        // airport and an airline were both addressed by their bare code, and
        // the pattern still takes either. Only the controller turns them away.
        self::assertSame('Airport@show', Routes::resolve('/airport/LHR'));
        self::assertNull(Helper::placeCode('LHR', 3));

        self::assertSame('Airline@show', Routes::resolve('/airline/AC'));
        self::assertNull(Helper::placeCode('AC', 2));
    }

    /**
     * The four indexes, which are ordinary fixed routes and the only way in to
     * the pages above.
     */
    public function testEachDirectoryIsARoute(): void
    {
        $indexes = [
            '/cities' => 'City@index',
            '/countries' => 'Country@index',
            '/airports' => 'Airports@index',
            '/airlines' => 'Airlines@index',
        ];

        foreach ($indexes as $path => $route) {
            self::assertSame($route, Routes::resolve($path));

            // Public, so the sitemap lists them and no robots tag keeps them
            // out.
            self::assertTrue(Routes::isPublic($path), $path . ' should be public');
        }
    }
}
