<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The things that can happen to a resource this app otherwise leaves
 * unlogged (G5.1, #316).
 *
 * A backed enum for the same reason `BookingEvent` is one: the stored word
 * and the case cannot drift, and `tryFrom()` lets a row written by an older
 * version still read.
 */
enum AdminEvent: string
{
    case Edited = 'edited';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Edited => 'edited',
            self::Removed => 'removed',
        };
    }
}
