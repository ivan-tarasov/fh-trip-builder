<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * Where a booking stands.
 *
 * The column has existed since the table was created and only ever held
 * 'confirmed', because the one action offered on a booking deleted the row
 * outright. Cancelling now writes here instead, so a cancellation is something
 * the traveller can still see rather than an absence they have to remember.
 */
enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * Resolve a stored value, falling back to confirmed for anything missing or
     * unrecognised -- every row written before this enum existed says
     * 'confirmed', and a row that somehow says nothing was still paid for.
     */
    public static function fromRow(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Confirmed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
        };
    }
}
