<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\GreatCircle;
use TripBuilder\View\StaticMap;

/**
 * The map URL, which five pages now ask for instead of spelling themselves.
 *
 * Mapbox checks the token before it looks at the URL -- a deliberately
 * malformed overlay and a well-formed one both come back 401 with a dummy
 * token, which was measured -- so nothing here can be confirmed against the
 * live service without a real one. What is asserted instead is everything the
 * documented format fixes: where the token goes, what frames the picture, and
 * the two orderings that are wrong in a way no error would report.
 *
 * The token is set to a dummy here and put back afterwards, so the suite
 * behaves the same on a machine that has a real one and on one that does not.
 */
final class StaticMapTest extends TestCase
{
    private const string DUMMY = 'pk.dummy-token-for-tests';

    private string|false $realToken = false;

    protected function setUp(): void
    {
        new Config('common');

        $this->realToken = getenv('MAPBOX_TOKEN');
        putenv('MAPBOX_TOKEN=' . self::DUMMY);
        $_ENV['MAPBOX_TOKEN'] = self::DUMMY;
    }

    protected function tearDown(): void
    {
        if (is_string($this->realToken)) {
            putenv('MAPBOX_TOKEN=' . $this->realToken);
            $_ENV['MAPBOX_TOKEN'] = $this->realToken;

            return;
        }

        putenv('MAPBOX_TOKEN');
        unset($_ENV['MAPBOX_TOKEN']);
    }

    /** @return list<array{lat: float, lon: float}> */
    private static function oneMarker(): array
    {
        return [['lat' => 51.4706, 'lon' => -0.4619]];
    }

    /**
     * No token, no map -- and specifically no URL, rather than a URL that will
     * answer 401 and leave a broken image on the page.
     *
     * This is the state the app is in until a token is configured, so it is the
     * behaviour that has to be right first.
     */
    public function testWithoutATokenThereIsNoUrl(): void
    {
        putenv('MAPBOX_TOKEN=');
        unset($_ENV['MAPBOX_TOKEN']);

        self::assertSame('', StaticMap::url(self::oneMarker(), [], 10));
    }

    /**
     * And nothing to draw is no map either, whatever the token.
     *
     * A Mapbox URL with no overlay and no centre is not a smaller picture, it
     * is a request that cannot be answered.
     */
    public function testWithNothingToDrawThereIsNoUrl(): void
    {
        self::assertSame('', StaticMap::url([], []));
    }

    public function testTheTokenIsSentAsAQueryParameter(): void
    {
        $url = StaticMap::url(self::oneMarker(), [], 10);

        self::assertStringContainsString('access_token=' . self::DUMMY, $url);
        // Never in the path, where it would be cached and logged as part of the
        // resource rather than as a credential.
        self::assertStringNotContainsString(self::DUMMY, parse_url($url, PHP_URL_PATH) ?: '');
    }

    /**
     * A single pin is centred and zoomed, because a frame fitted to one point
     * has no size. This is the airport page.
     */
    public function testASinglePinIsCentredAtAnExplicitZoom(): void
    {
        $url = StaticMap::url(self::oneMarker(), [], 10);

        self::assertStringContainsString('/static/pin-s(-0.4619,51.4706)/-0.4619,51.4706,10/', $url);
        // Padding frames an automatic view. There is no frame here to pad.
        self::assertStringNotContainsString('padding', $url);
    }

    /**
     * Several pins frame themselves. This is the city, country and airline
     * pages, where the number of pins is whatever the place has.
     */
    public function testSeveralPinsAreFramedAutomaticallyAndPadded(): void
    {
        $url = StaticMap::url([
            ['lat' => 51.4706, 'lon' => -0.4619],
            ['lat' => 51.1537, 'lon' => -0.1821],
            ['lat' => 51.8860, 'lon' => 0.2389],
        ]);

        self::assertStringContainsString('/auto/', $url);
        self::assertStringContainsString('padding=40', $url);
        self::assertSame(3, substr_count($url, 'pin-s('), 'one pin per airport');
    }

    /**
     * Paths are drawn before pins, because overlays are drawn in the order
     * given and a line over a pin hides the thing the line connects.
     *
     * Nothing would report this: the picture renders, and the pins are simply
     * under the line.
     */
    public function testPathsAreDrawnUnderTheMarkers(): void
    {
        $url = StaticMap::url(
            [
                ['lat' => 40.7019, 'lon' => -73.9462, 'colour' => '0EB600'],
                ['lat' => 51.5032, 'lon' => -0.1228, 'colour' => 'EC3735'],
            ],
            GreatCircle::segments(40.7019, -73.9462, 51.5032, -0.1228),
        );

        $path = strpos($url, 'path-5');
        $pin = strpos($url, 'pin-s');

        self::assertNotFalse($path, 'the flight path should be drawn');
        self::assertNotFalse($pin, 'the ends should be pinned');
        self::assertLessThan($pin, $path, 'the path belongs under the pins');
    }

    /**
     * A route over the antimeridian is two paths, and each is its own overlay.
     */
    public function testASplitRouteBecomesTwoPathOverlays(): void
    {
        $url = StaticMap::url(
            [['lat' => 35.6612, 'lon' => 140.0860], ['lat' => 33.9423, 'lon' => -118.4069]],
            GreatCircle::segments(35.6612, 140.0860, 33.9423, -118.4069),
        );

        self::assertSame(2, substr_count($url, 'path-5+'), 'the cut arc is two lines');
    }

    /**
     * The encoded polyline has to be URL-encoded, and this is the assertion
     * that says so.
     *
     * The format emits backslashes, backticks and question marks. A bare
     * backtick is legal in a URL and ends the overlay early; a bare question
     * mark starts the query string in the middle of the path.
     */
    public function testThePolylineIsUrlEncoded(): void
    {
        $url = StaticMap::url(
            self::oneMarker(),
            GreatCircle::segments(40.7019, -73.9462, 51.5032, -0.1228),
        );

        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach ([chr(96), '\\', '?', '|', '[', ']'] as $raw) {
            self::assertStringNotContainsString(
                $raw,
                $path,
                'the path segment should carry no raw ' . $raw,
            );
        }

        // And the encoding is actually there rather than the polyline being
        // empty.
        self::assertStringContainsString('%', $path);
    }

    /**
     * A colour may be written with or without its hash, because CSS writes one
     * and this URL cannot carry it -- a bare "#" starts the fragment.
     */
    public function testAMarkerColourIsAcceptedWithOrWithoutItsHash(): void
    {
        $withHash = StaticMap::url([['lat' => 51.5, 'lon' => -0.12, 'colour' => '#EC3735']], [], 10);
        $without = StaticMap::url([['lat' => 51.5, 'lon' => -0.12, 'colour' => 'EC3735']], [], 10);

        self::assertSame($withHash, $without);
        self::assertStringContainsString('pin-s+EC3735(', $withHash);
        self::assertStringNotContainsString('#', $withHash);
    }

    /**
     * The picture's shape comes from config, so it is one edit and not five.
     */
    public function testTheStyleAndSizeComeFromConfig(): void
    {
        $url = StaticMap::url(self::oneMarker(), [], 10);

        self::assertStringContainsString('/' . Config::get('maps.static.style') . '/static/', $url);
        self::assertStringContainsString(
            sprintf(
                '/%dx%d',
                (int) Config::get('maps.static.width'),
                (int) Config::get('maps.static.height'),
            ),
            $url,
        );
    }
}
