<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The things that can happen to a booking.
 *
 * Three, because three is what the application can actually do to one: it is
 * created at checkout, it can be cancelled, and — now that there is a panel —
 * a cancellation can be undone. Anything else a booking log might want to show
 * (a refund, a schedule change, an email sent) would need the thing itself to
 * exist first, and none of them do (A3.8, #233).
 *
 * A backed enum so the stored word and the case cannot drift, and so a row
 * written by an older version still reads: `tryFrom()` gives null for a case
 * this version does not know, and the page prints the raw word rather than
 * refusing to draw the log.
 */
enum BookingEvent: string
{
    case Booked = 'booked';
    case Cancelled = 'cancelled';
    case Reinstated = 'reinstated';

    /** What the log says happened. */
    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::Cancelled => 'Cancelled',
            self::Reinstated => 'Reinstated',
        };
    }
}
