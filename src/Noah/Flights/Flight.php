<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

/**
 * A generated flight row, ready to insert into the `flights` table.
 */
final readonly class Flight
{
    public function __construct(
        public string $airline,
        public int $number,
        public ?string $aircraft,
        public string $departureAirport,
        public string $departureTime,
        public string $arrivalAirport,
        public string $arrivalTime,
        public int $distance,
        public int $duration,
        public float $priceBase,
        public float $priceTax,
        public float $rating,
    ) {}

    /**
     * Column names in insert order.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'airline', 'number', 'aircraft', 'departure_airport', 'departure_time',
            'arrival_airport', 'arrival_time', 'distance', 'duration',
            'price_base', 'price_tax', 'rating',
        ];
    }

    /**
     * Values matching self::columns() order.
     *
     * @return list<string|int|float|null>
     */
    public function toValues(): array
    {
        return [
            $this->airline,
            $this->number,
            $this->aircraft,
            $this->departureAirport,
            $this->departureTime,
            $this->arrivalAirport,
            $this->arrivalTime,
            $this->distance,
            $this->duration,
            $this->priceBase,
            $this->priceTax,
            $this->rating,
        ];
    }
}
