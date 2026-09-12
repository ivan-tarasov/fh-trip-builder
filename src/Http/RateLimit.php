<?php

declare(strict_types=1);

namespace TripBuilder\Http;

/**
 * The writes anybody can reach, and how often one client may reach them.
 *
 * Three endpoints take a row from an unauthenticated request: the fare-alert
 * subscribe, the two votes and checkout. Each is a free `INSERT` to anyone
 * holding a CSRF token, which is free to get -- the token is on every page.
 *
 * The numbers are generous for a person and a wall for a script. Somebody who
 * subscribes ten times in an hour has not been stopped from anything they
 * meant to do; somebody inserting the eleventh is not a person.
 */
enum RateLimit: string
{
    case Subscribe = 'subscribe';
    case Vote = 'vote';
    case Checkout = 'checkout';

    /**
     * Requests one client may make in one hour.
     */
    public function perHour(): int
    {
        return match ($this) {
            // An address is given once. Ten allows for typos, a second
            // address, and a household.
            self::Subscribe => 10,

            // Reading a run of help articles and voting on each is ordinary,
            // and a vote is one row that replaces itself per voter.
            self::Vote => 60,

            // A booking is minutes of typing. Twenty in an hour is already
            // more than anybody does, and this one guards a card form.
            self::Checkout => 20,
        };
    }

    /**
     * What the caller is told. No numbers and no window: a limit that
     * describes itself is a limit that can be timed against.
     */
    public function refusal(): string
    {
        return 'Too many attempts from here. Try again a bit later.';
    }
}
