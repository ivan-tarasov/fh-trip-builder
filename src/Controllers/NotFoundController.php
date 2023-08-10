<?php

namespace TripBuilder\Controllers;

use TripBuilder\Templater;

class NotFoundController
{
    public function index()
    {
        $templater = new Templater('error', '404-not-found');

        echo $templater->save()->render();
    }
}
