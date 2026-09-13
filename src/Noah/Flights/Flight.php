<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

/**
 * A generated flight row, ready to insert into the `flights` table.
 *
 * `departureTime` is local at the departure airport -- what a ticket says --
 * and `departureUtc` is the same moment as an instant. Both, because they are
 * asked different questions: "flights on the 20th" means the 20th *there*, and
 * "has it left yet" cannot be answered by a wall clock (E20, #180).
 */
final readonly class Flight
{
    public function __construct(
        public string $airline,
        public int $number,
        public ?string $aircraft,
        public ?string $fareBrand,
        public string $departureAirport,
        public string $departureTime,
        public string $departureUtc,
        public string $arrivalAirport,
        public string $arrivalTime,
        public int $distance,
        public int $duration,
        public int $cabins,
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
            'airline', 'number', 'aircraft', 'fare_brand', 'departure_airport',
            'departure_time', 'departure_utc', 'arrival_airport', 'arrival_time', 'distance',
            'duration', 'cabins', 'price_base', 'price_tax', 'rating',
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
            $this->fareBrand,
            $this->departureAirport,
            $this->departureTime,
            $this->departureUtc,
            $this->arrivalAirport,
            $this->arrivalTime,
            $this->distance,
            $this->duration,
            $this->cabins,
            $this->priceBase,
            $this->priceTax,
            $this->rating,
        ];
    }
}
