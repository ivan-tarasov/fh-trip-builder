<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah\Flights;

use PHPUnit\Framework\TestCase;
use TripBuilder\Noah\Flights\Flight;

final class FlightTest extends TestCase
{
    public function testColumnsAndValuesAlign(): void
    {
        $flight = new Flight(
            airline: 'AC',
            number: 101,
            departureAirport: 'YUL',
            departureTime: '2026-09-15 06:00:00',
            arrivalAirport: 'YYZ',
            arrivalTime: '2026-09-15 07:15:00',
            distance: 504,
            duration: 75,
            priceBase: 120.0,
            priceTax: 18.0,
            rating: 4.1,
        );

        $columns = Flight::columns();
        $values = $flight->toValues();

        self::assertCount(count($columns), $values);

        $row = array_combine($columns, $values);
        self::assertSame('AC', $row['airline']);
        self::assertSame(101, $row['number']);
        self::assertSame('YUL', $row['departure_airport']);
        self::assertSame('YYZ', $row['arrival_airport']);
        self::assertSame(504, $row['distance']);
        self::assertSame(120.0, $row['price_base']);
    }
}
