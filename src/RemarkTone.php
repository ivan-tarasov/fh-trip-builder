<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The mark a remark carries -- what kind of note it is, not what it says.
 *
 * Two, because two is what was asked for: an ordinary note, and one worth
 * not missing. A backed enum so the stored word and the case cannot drift,
 * and so a remark written by an older or newer version still reads --
 * {@see BookingRemarkRepository::forBooking()} falls back to the raw word
 * for a tone this version does not know.
 */
enum RemarkTone: string
{
    case Info = 'info';
    case Important = 'important';

    /** What the form and the list call it. */
    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Important => 'Important',
        };
    }

    /**
     * The `.status-chip--*` modifier it wears -- reusing the existing tones
     * rather than inventing a third just for this.
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Info => 'muted',
            self::Important => 'bad',
        };
    }
}
