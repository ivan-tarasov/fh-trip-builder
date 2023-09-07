<?php

namespace TripBuilder\DataBase;

use MysqliDb;

class MySql
{
    const TABLE_AIRLINES  = 'airlines',
          TABLE_AIRPORTS  = 'airports',
          TABLE_BOOKINGS  = 'bookings',
          TABLE_COUNTRIES = 'countries',
          TABLE_FLIGHTS   = 'flights',
          TABLE_SEARCH    = 'search';

    public static function connect()
    {
        return new MysqliDb(
            $_ENV['DB_HOST'],
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            $_ENV['DB_DATABASE']
        );
    }

}
