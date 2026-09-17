<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * What kind of thing an admin event happened to.
 *
 * Started at the two things G5.1 (#316) found with nothing logged at all:
 * content and the fare-alert list. Bookings and settings already have their
 * own logs and stay on them -- this is for what was left out, not a
 * replacement for either.
 */
enum AdminEventResource: string
{
    case Article = 'article';
    case Category = 'category';
    case Subscriber = 'subscriber';
    case Search = 'search';

    public function label(): string
    {
        return match ($this) {
            self::Article => 'Article',
            self::Category => 'Category',
            self::Subscriber => 'Subscriber',
            self::Search => 'Search',
        };
    }
}
