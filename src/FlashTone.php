<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * What a queued {@see Flash} message is about -- the thing said, not the
 * thing that happened, so the same "nothing changed" wording can follow a
 * bad CSRF token or a booking that was not in the state a button expected.
 */
enum FlashTone: string
{
    case Success = 'success';
    case Error = 'error';

    /** The Bootstrap `text-bg-*` suffix this tone wears. */
    public function bootstrapClass(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Error => 'danger',
        };
    }
}
