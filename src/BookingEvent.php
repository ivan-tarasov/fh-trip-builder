<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The things that can happen to a booking.
 *
 * Started at three -- created at checkout, cancelled, and a cancellation
 * undone -- because that was what the application could actually do to one.
 * The rule was that anything else a booking log might want to show (a
 * refund, a ticket, an email sent) needed the thing itself to exist first;
 * tickets now do (G8.3, #338), so every write `BookingTicketRepository`
 * causes gets a case here too, the same as the booking's own status moves.
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
    case TicketAdded = 'ticket_added';
    case TicketStatusChanged = 'ticket_status_changed';
    case TicketNumberChanged = 'ticket_number_changed';
    case TicketRemoved = 'ticket_removed';

    /** What the log says happened. */
    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::Cancelled => 'Cancelled',
            self::Reinstated => 'Reinstated',
            self::TicketAdded => 'Ticket added',
            self::TicketStatusChanged => 'Ticket status changed',
            self::TicketNumberChanged => 'Ticket number changed',
            self::TicketRemoved => 'Ticket removed',
        };
    }
}
