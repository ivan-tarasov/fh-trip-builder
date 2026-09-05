<?php

declare(strict_types=1);

namespace TripBuilder\Database;

/**
 * Single source of truth for database table names.
 */
enum Table: string
{
    case Aircraft = 'aircraft';
    case AircraftCabins = 'aircraft_cabins';
    case Airlines = 'airlines';
    case Airports = 'airports';
    case Bookings = 'bookings';
    case BookingPassengers = 'booking_passengers';
    case Countries = 'countries';
    case FareBrands = 'fare_brands';
    case Flights = 'flights';
    case Search = 'search';
}
