<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

/**
 * What LegBuilder decided about one leg: the type flying it, how long it takes
 * and which cabins are on sale.
 *
 * The three travel together because they are one decision. The type sets the
 * speed, and therefore the duration; the type also sets the cabins. Passing
 * them separately invites a caller to write a duration from one draw and a
 * cabin mask from another.
 */
final readonly class LegAssignment
{
    public function __construct(
        public ?string $aircraft,
        public int $duration,
        public int $cabins,
    ) {}
}
