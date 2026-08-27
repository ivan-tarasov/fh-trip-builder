<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The two supported trip types.
 *
 * Backed by the string values used everywhere the concept crosses a boundary:
 * the request query, the flights API payload, the search DB record and the
 * templates. Replaces the self-referential search.triptype config map.
 */
enum TripType: string
{
    case Roundtrip = 'roundtrip';
    case Oneway = 'oneway';

    /**
     * Resolve a raw request value, falling back to one-way for anything
     * missing or unrecognised (the search form's default).
     */
    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Oneway;
    }
}
