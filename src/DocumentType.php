<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The kind of travel document a ticket row holds.
 *
 * Two, because two is the reason a passenger needs more than one row on
 * `booking_tickets` in the first place (G8.3, #338): a ticket and an EMD,
 * or a reissue after a schedule change. A backed enum so the stored word
 * and the case cannot drift, and so a row written by a later version still
 * reads -- {@see BookingTicketRepository::forBooking()} falls back to the
 * raw word for a type this version does not know.
 */
enum DocumentType: string
{
    case Ticket = 'ticket';
    case Emd = 'emd';

    public function label(): string
    {
        return match ($this) {
            self::Ticket => 'Ticket',
            self::Emd => 'EMD',
        };
    }
}
