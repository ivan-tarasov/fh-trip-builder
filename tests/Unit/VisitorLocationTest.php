<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Http\Input;
use TripBuilder\Http\Request;
use TripBuilder\VisitorLocation;

/**
 * The coordinates the edge puts on a request.
 *
 * Read off a real request through Cloudflare rather than from the docs:
 * `HTTP_CF_IPLATITUDE = 45.50884`, `HTTP_CF_IPLONGITUDE = -73.58781`, which
 * `Request::header()` finds as `cf-iplatitude` and `cf-iplongitude` because it
 * lowercases and turns underscores into hyphens.
 */
final class VisitorLocationTest extends TestCase
{
    public function testItReadsTheCoordinatesTheEdgeSent(): void
    {
        // The values from an actual request, downtown Montreal.
        self::assertSame(
            ['latitude' => 45.50884, 'longitude' => -73.58781],
            VisitorLocation::coordinates($this->from([
                'cf-iplatitude' => '45.50884',
                'cf-iplongitude' => '-73.58781',
            ])),
        );
    }

    /**
     * No edge, no answer -- which is every local request and every direct hit
     * on the origin, so it is the ordinary case rather than a failure.
     */
    public function testWithNoHeadersThereIsNoLocation(): void
    {
        self::assertNull(VisitorLocation::coordinates($this->from([])));
    }

    public function testOneCoordinateAloneIsNoLocation(): void
    {
        self::assertNull(VisitorLocation::coordinates($this->from(['cf-iplatitude' => '45.50884'])));
        self::assertNull(VisitorLocation::coordinates($this->from(['cf-iplongitude' => '-73.58781'])));
    }

    /**
     * @return iterable<string, array{string, string}>
     *
     * A header is input like any other: it arrives on a request, and a proxy
     * misconfigured to pass one through from the client is how it would arrive
     * wrong.
     */
    public static function unusable(): iterable
    {
        yield 'a word' => ['unknown', 'unknown'];
        yield 'empty' => ['', ''];
        yield 'latitude past the pole' => ['91', '0'];
        yield 'longitude past the meridian' => ['0', '181'];
        yield 'not quite a number' => ['45.5deg', '-73.5'];
    }

    #[DataProvider('unusable')]
    public function testAnythingThatIsNotACoordinateIsRefused(string $latitude, string $longitude): void
    {
        self::assertNull(VisitorLocation::coordinates($this->from([
            'cf-iplatitude' => $latitude,
            'cf-iplongitude' => $longitude,
        ])));
    }

    /**
     * And the trap that makes `is_numeric` the check rather than a cast.
     *
     * `(float) 'unknown'` is 0.0, which is not an absence -- it is a point in
     * the Gulf of Guinea. Silently filling the field from there would be the
     * quietest possible way to be wrong.
     */
    public function testAWordDoesNotBecomeNullIsland(): void
    {
        self::assertSame(0.0, (float) 'unknown', 'the cast this avoids');
        self::assertNull(VisitorLocation::coordinates($this->from([
            'cf-iplatitude' => 'unknown',
            'cf-iplongitude' => 'unknown',
        ])));
    }

    /**
     * Zero is a real coordinate, though, and is not the caller's problem: the
     * radius refuses the Gulf of Guinea because nothing we sell from is inside
     * it.
     */
    public function testZeroIsStillACoordinate(): void
    {
        self::assertSame(
            ['latitude' => 0.0, 'longitude' => 0.0],
            VisitorLocation::coordinates($this->from([
                'cf-iplatitude' => '0',
                'cf-iplongitude' => '0',
            ])),
        );
    }

    /** @param array<string, string> $headers */
    private function from(array $headers): Request
    {
        return new Request(new Input(), new Input(), new Input(), headers: $headers);
    }
}
