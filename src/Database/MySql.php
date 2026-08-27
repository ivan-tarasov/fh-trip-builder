<?php

declare(strict_types=1);

namespace TripBuilder\Database;

use MysqliDb;

class MySql
{
    public const TABLE_AIRLINES  = Table::Airlines->value,
        TABLE_AIRPORTS  = Table::Airports->value,
        TABLE_BOOKINGS  = Table::Bookings->value,
        TABLE_COUNTRIES = Table::Countries->value,
        TABLE_FLIGHTS   = Table::Flights->value,
        TABLE_SEARCH    = Table::Search->value;

    public static function connect(): MysqliDb
    {
        return new MysqliDb(
            $_ENV['DB_HOST'],
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            $_ENV['DB_DATABASE'],
        );
    }

}
