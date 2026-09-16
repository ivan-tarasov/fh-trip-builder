<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * Where one travel document stands.
 *
 * Free transitions between all three -- nothing here enforces an order a
 * real GDS might, because this app has no real GDS behind it and inventing
 * a state machine the issue never asked for is not this task's job
 * (G8.3, #338).
 */
enum TicketStatus: string
{
    case Issued = 'issued';
    case Voided = 'voided';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Voided => 'Voided',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * The `.status-chip--*` modifier it wears. Issued reads as good news,
     * voided as the thing to notice (the document is now worthless);
     * refunded is neither, just a fact worth stating plainly.
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Issued => 'good',
            self::Voided => 'bad',
            self::Refunded => 'muted',
        };
    }
}
