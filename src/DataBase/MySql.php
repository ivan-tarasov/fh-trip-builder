<?php

namespace TripBuilder\DataBase;

use MysqliDb;

class MySql
{
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
