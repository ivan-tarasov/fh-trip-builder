<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * Who did it — a kind of party, never a person.
 *
 * There is one operator and no accounts table, so recording a name would be
 * recording the only name there is. What the log has to answer is different and
 * smaller: was this the traveller acting on their own booking, or somebody
 * behind the panel acting on it for them (A3.8, #233).
 */
enum BookingActor: string
{
    /** The browser that made the booking, acting on it from `/my/bookings`. */
    case Visitor = 'visitor';

    /** Somebody signed in to the panel. */
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Visitor => 'the traveller',
            self::Operator => 'the panel',
        };
    }
}
