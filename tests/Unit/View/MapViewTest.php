<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\GreatCircle;
use TripBuilder\View\MapView;

/**
 * The map, in both the forms a page asks for: a live one and a picture.
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
final class MapViewTest extends TestCase
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

        self::assertSame('', MapView::image(self::oneMarker(), [], 10));
    }

    /**
     * And nothing to draw is no map either, whatever the token.
     *
     * A Mapbox URL with no overlay and no centre is not a smaller picture, it
     * is a request that cannot be answered.
     */
    public function testWithNothingToDrawThereIsNoUrl(): void
    {
        self::assertSame('', MapView::image([], []));
    }

    public function testTheTokenIsSentAsAQueryParameter(): void
    {
        $url = MapView::image(self::oneMarker(), [], 10);

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
        $url = MapView::image(self::oneMarker(), [], 10);

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
        $url = MapView::image([
            ['lat' => 51.4706, 'lon' => -0.4619],
            ['lat' => 51.1537, 'lon' => -0.1821],
            ['lat' => 51.8860, 'lon' => 0.2389],
        ]);

        self::assertStringContainsString('/auto/', $url);
        // Read from config, not written out here: the number is a design
        // decision that moved once already, and a test repeating it just has to
        // be edited alongside.
        self::assertStringContainsString('padding=' . (int) Config::get('maps.static.padding'), $url);
        self::assertSame(3, substr_count($url, 'pin-s('), 'one pin per airport');
    }

    /**
     * One pin and no zoom asked for gets the configured one.
     *
     * This is the airport page, which no longer names a zoom: a single point
     * cannot be framed -- a box around it has no size -- so what "around here"
     * means is one decision for the whole site. Both halves have to reach the
     * same answer, or the picture and the live map open on different views.
     */
    public function testASinglePinFallsBackToTheConfiguredZoom(): void
    {
        $expected = (int) Config::get('maps.static.zoom_single');

        self::assertStringContainsString(
            ',' . $expected . '/',
            MapView::image(self::oneMarker()),
            'the picture should open at the configured zoom',
        );

        $config = json_decode(MapView::config(self::oneMarker()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($expected, $config['zoom'], 'and so should the live map');
    }

    /**
     * Two pins frame themselves, and a caller's own zoom still wins.
     */
    public function testTheFallbackAppliesOnlyToASinglePinWithNoPath(): void
    {
        $two = [['lat' => 51.4706, 'lon' => -0.4619], ['lat' => 51.1537, 'lon' => -0.1821]];

        $framed = json_decode(MapView::config($two), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($framed['zoom'], 'two pins frame themselves');

        $withPath = json_decode(
            MapView::config(
                self::oneMarker(),
                [[['lat' => 51.5, 'lon' => -0.1], ['lat' => 40.7, 'lon' => -73.9]]],
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertNull($withPath['zoom'], 'a path is something to frame');

        $asked = json_decode(MapView::config(self::oneMarker(), [], 12), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(12, $asked['zoom'], "a caller's own zoom wins");
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
        $url = MapView::image(
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
        $url = MapView::image(
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
        $url = MapView::image(
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
        $withHash = MapView::image([['lat' => 51.5, 'lon' => -0.12, 'colour' => '#EC3735']], [], 10);
        $without = MapView::image([['lat' => 51.5, 'lon' => -0.12, 'colour' => 'EC3735']], [], 10);

        self::assertSame($withHash, $without);
        self::assertStringContainsString('pin-s+EC3735(', $withHash);
        self::assertStringNotContainsString('#', $withHash);
    }

    /**
     * Coordinates arrive as strings, and this is the test that was missing.
     *
     * PDO returns a DECIMAL column as a string, so every latitude and longitude
     * on these five pages is "51.47060000" and not 51.4706. The first version
     * of this class typed them `float`, which threw on all five pages -- and
     * every test above passed, because they are written with float literals.
     * A fixture that does not look like the data is a test of something else.
     */
    public function testCoordinatesMayArriveAsStringsFromTheDatabase(): void
    {
        $asStrings = MapView::image(
            [
                ['lat' => '40.70190000', 'lon' => '-73.94620000', 'colour' => '0EB600'],
                ['lat' => '51.50323333', 'lon' => '-0.12276667', 'colour' => 'EC3735'],
            ],
            [[
                ['lat' => '40.70190000', 'lon' => '-73.94620000'],
                ['lat' => '51.50323333', 'lon' => '-0.12276667'],
            ]],
        );

        self::assertStringContainsString('pin-s+0EB600(-73.9462,40.7019)', $asStrings);
        self::assertStringContainsString('path-5+', $asStrings);

        // And the same numbers as floats give the same picture.
        self::assertSame(
            MapView::image(
                [
                    ['lat' => 40.7019, 'lon' => -73.9462, 'colour' => '0EB600'],
                    ['lat' => 51.50323333, 'lon' => -0.12276667, 'colour' => 'EC3735'],
                ],
                [[
                    ['lat' => 40.7019, 'lon' => -73.9462],
                    ['lat' => 51.50323333, 'lon' => -0.12276667],
                ]],
            ),
            $asStrings,
        );
    }

    /**
     * The live map is handed over as JSON, and it has to be JSON: the browser
     * parses it out of an attribute.
     */
    public function testTheLiveMapIsValidJsonCarryingTheToken(): void
    {
        $json = MapView::config(self::oneMarker(), [], 10);

        $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($config);
        self::assertSame(self::DUMMY, $config['token']);
        self::assertSame(10, $config['zoom']);
        // GL JS takes the mapbox:// form where the picture endpoint takes it
        // bare, so the one config value has to be spelled two ways.
        self::assertStringStartsWith('mapbox://styles/', $config['style']);
    }

    public function testWithoutATokenThereIsNoLiveMapEither(): void
    {
        putenv('MAPBOX_TOKEN=');
        unset($_ENV['MAPBOX_TOKEN']);

        self::assertSame('', MapView::config(self::oneMarker(), [], 10));
        self::assertSame('', MapView::config([], []));
    }

    /**
     * Everything crossing into JavaScript is [lon, lat].
     *
     * The three orders in play here are why this is asserted rather than
     * trusted: this codebase reads lat then lon, the encoded polyline the
     * picture uses is lat then lon, and GeoJSON -- which is what GL JS takes --
     * is lon then lat. Getting it wrong puts London in the Indian Ocean and
     * throws nothing.
     */
    public function testCoordinatesCrossIntoJavascriptInGeojsonOrder(): void
    {
        $config = json_decode(
            MapView::config(
                [['lat' => 51.5032, 'lon' => -0.1228]],
                [[['lat' => 51.5032, 'lon' => -0.1228], ['lat' => 40.7019, 'lon' => -73.9462]]],
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame([-0.1228, 51.5032], $config['markers'][0]['at'], 'a marker is [lon, lat]');
        self::assertSame([-0.1228, 51.5032], $config['paths'][0][0], 'and so is a path point');
        self::assertSame([-73.9462, 40.7019], $config['paths'][0][1]);
    }

    /**
     * A colour reaches the browser as CSS writes it, with the hash, because
     * that is what GL JS wants -- and the picture endpoint wants it without.
     * One input, two spellings.
     */
    public function testColoursReachTheBrowserWithTheirHash(): void
    {
        $config = json_decode(
            MapView::config([
                ['lat' => 40.7019, 'lon' => -73.9462, 'colour' => '0EB600'],
                ['lat' => 51.5032, 'lon' => -0.1228, 'colour' => '#EC3735'],
            ]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('#0EB600', $config['markers'][0]['colour']);
        self::assertSame('#EC3735', $config['markers'][1]['colour']);
        self::assertStringStartsWith('#', $config['path']['colour']);
    }

    /**
     * A route over the antimeridian is two paths here too, and each is its own
     * line -- joined into one, the map draws it back around the world.
     */
    public function testASplitRouteReachesTheBrowserAsTwoPaths(): void
    {
        $config = json_decode(
            MapView::config(
                [['lat' => 35.6612, 'lon' => 140.0860]],
                GreatCircle::segments(35.6612, 140.0860, 33.9423, -118.4069),
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertCount(2, $config['paths']);
    }

    /**
     * The database's strings survive the trip into JSON as numbers.
     *
     * JavaScript would take "51.4706" for a coordinate and quietly cope; GL JS
     * would not, and neither would a bounding box computed from it. Same bug as
     * the picture had, so it is asserted on both halves.
     */
    public function testCoordinatesReachTheBrowserAsNumbers(): void
    {
        $config = json_decode(
            MapView::config([['lat' => '51.46960000', 'lon' => '-0.45360000']], [], 10),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame([-0.4536, 51.4696], $config['markers'][0]['at']);
    }

    /**
     * The picture's shape comes from config, so it is one edit and not five.
     */
    public function testTheStyleAndSizeComeFromConfig(): void
    {
        $url = MapView::image(self::oneMarker(), [], 10);

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
