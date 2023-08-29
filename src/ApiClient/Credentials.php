<?php

namespace TripBuilder\ApiClient;

use TripBuilder\Config;

class Credentials
{
    public static function getBearer()
    {
        return 'Bearer ' . Config::get('api.fake.token');
    }

}
