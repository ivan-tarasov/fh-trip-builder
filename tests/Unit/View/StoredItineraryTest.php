<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use TripBuilder\View\StoredItinerary;

final class StoredItineraryTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $overrides
     */
    private static function json(array $overrides): string
    {
        return (string) json_encode($overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function segment(string $from, string $to, string $depart, string $arrive, int $duration): array
    {
        return [
            'id' => 1,
            'carrier' => 'AC',
            'carrier_name' => 'Air Canada',
            'number' => 'AC-100',
            'duration' => $duration,
            'cabin_code' => 'Y',
            'depart' => [
                'airport_code' => $from,
                'airport_name' => $from . ' International',
                'airport_city' => $from . ' City',
                'airport_country' => 'Canada',
                'date_time' => $depart,
            ],
            'arrive' => [
                'airport_code' => $to,
                'airport_name' => $to . ' International',
                'airport_city' => $to . ' City',
                'airport_country' => 'Canada',
                'date_time' => $arrive,
            ],
        ];
    }

    public function testDerivesTheWrapperThePresenterNeeds(): void
    {
        $itinerary = StoredItinerary::fromJson(self::json([
            self::segment('YUL', 'YYZ', '2026-09-08 07:00', '2026-09-08 08:30', 90),
            self::segment('YYZ', 'LHR', '2026-09-08 10:00', '2026-09-08 21:00', 420),
        ]));

        self::assertNotNull($itinerary);
        self::assertSame(1, $itinerary->stops);
        self::assertCount(1, $itinerary->layovers);
        // 90 + 420 flying, plus the 90-minute wait between them. Never a
        // subtraction of the two outer stamps, which are in different zones.
        self::assertSame(600, $itinerary->total_duration);
        self::assertSame([], $itinerary->badges);
    }

    public function testLayoversAreObjectsThePresenterCanReadThrough(): void
    {
        $itinerary = StoredItinerary::fromJson(self::json([
            self::segment('YUL', 'YYZ', '2026-09-08 07:00', '2026-09-08 08:30', 90),
            self::segment('YYZ', 'LHR', '2026-09-08 10:00', '2026-09-08 21:00', 420),
        ]));

        $layover = $itinerary->layovers[0];

        // Arrays would survive this class and fail at render: the presenter
        // reads these with -> and Twig would never see them.
        self::assertInstanceOf(stdClass::class, $layover);
        self::assertSame('YYZ', $layover->airport_code);
        self::assertSame('YYZ City', $layover->airport_city);
        self::assertSame('YYZ International', $layover->airport_name);
        self::assertSame(90, $layover->wait_minutes);
    }

    public function testASingleSegmentIsDirectWithNothingToWaitFor(): void
    {
        $itinerary = StoredItinerary::fromJson(self::json([
            self::segment('YUL', 'LHR', '2026-09-08 07:00', '2026-09-08 19:00', 420),
        ]));

        self::assertSame(0, $itinerary->stops);
        self::assertSame([], $itinerary->layovers);
        self::assertSame(420, $itinerary->total_duration);
    }

    public function testALegacySegmentWithNoAircraftDataStillBuilds(): void
    {
        // Ten of the twelve bookings in the live table were written before the
        // aircraft and seat columns existed. They must not be skipped.
        $segment = self::segment('YUL', 'LHR', '2026-09-08 07:00', '2026-09-08 19:00', 420);
        self::assertArrayNotHasKey('aircraft', $segment);

        $itinerary = StoredItinerary::fromJson(self::json([$segment]));

        self::assertNotNull($itinerary);
        self::assertCount(1, $itinerary->segments);
    }

    public function testASingleStoredObjectIsReadAsOneSegment(): void
    {
        $itinerary = StoredItinerary::fromJson(
            (string) json_encode(self::segment('YUL', 'LHR', '2026-09-08 07:00', '2026-09-08 19:00', 420)),
        );

        self::assertNotNull($itinerary);
        self::assertSame(0, $itinerary->stops);
    }

    /**
     * @return list<array{0: string|null}>
     */
    public static function unrenderable(): array
    {
        return [[null], [''], ['[]'], ['{}'], ['not json'], ['[1,2]'], ['[{"duration":10}]']];
    }

    #[DataProvider('unrenderable')]
    public function testNothingRenderableReturnsNullSoTheRowIsSkipped(?string $json): void
    {
        self::assertNull(StoredItinerary::fromJson($json));
    }
}
