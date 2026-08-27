<?php

declare(strict_types=1);

namespace TripBuilder\Database;

/**
 * Single source of truth for database table names.
 */
enum Table: string
{
    case Airlines = 'airlines';
    case Airports = 'airports';
    case Bookings = 'bookings';
    case Countries = 'countries';
    case Flights = 'flights';
    case Search = 'search';
}
