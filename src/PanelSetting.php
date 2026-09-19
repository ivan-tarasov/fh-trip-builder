<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * Every config key an operator may override from the panel, and everything
 * the form needs to know about one: where it groups, what it is called, why
 * it exists, how to read a posted value back out of a string, and what makes
 * one not worth keeping.
 *
 * A short list on purpose. #232's own research read all nine files in
 * `config/common` and found most of it coupled to code rather than a
 * preference -- a sort with no matching `SortMethod` case, an asset path that
 * would orphan every link built from it, thirty currencies each needing a
 * rate row. This names only what survived that read: real business rules,
 * recoverable when wrong, and three lines of site copy. `site.poi` -- the
 * homepage destination cards -- is the one candidate that read as *content*
 * rather than a setting, and is left out on purpose; it wants a table and an
 * ownership rule the way articles did, not a form field.
 *
 * `search.connections.roundtrip_topk` is in `config/common/search.php` and
 * is not here: nothing in `src/` reads it. Making it settable would let an
 * operator change a number that does nothing.
 */
enum PanelSetting: string
{
    case MinConnectMinutes = 'search.connections.min_connect_minutes';
    case MaxConnectMinutes = 'search.connections.max_connect_minutes';
    case MaxStops = 'search.connections.max_stops';
    case MaxDetourRatio = 'search.connections.max_detour_ratio';
    case MinDetourKm = 'search.connections.min_detour_km';
    case NightFromHour = 'search.filters.night_from_hour';
    case NightToHour = 'search.filters.night_to_hour';
    case GulfCountries = 'search.filters.gulf_countries';
    case AppName = 'app.name';
    case MetaDescription = 'meta.description';
    case MetaKeywords = 'meta.keywords';
    case MapStyle = 'maps.static.style';
    case HomeDealsLimit = 'site.home.deals_limit';
    case HomePopularLimit = 'site.home.popular_limit';

    // The operator's own identity (G9, #350) -- shown in the topbar user
    // menu and the public site's footer credit line (G6.4, #348), and
    // recoverable the same way every other field here is, rather than a
    // `.env` var with no way to edit it back (`ADMIN_PASSWORD_HASH`'s own
    // precedent is for a value with no in-app edit flow at all, which is
    // not what this is).
    case ProfileName = 'meta.author.name';
    case ProfileEmail = 'meta.author.email';
    case ProfileRole = 'meta.author.role';
    case ProfileAvatar = 'meta.author.avatar';

    public function group(): string
    {
        return match ($this) {
            self::MinConnectMinutes, self::MaxConnectMinutes, self::MaxStops,
            self::MaxDetourRatio, self::MinDetourKm,
            self::NightFromHour, self::NightToHour, self::GulfCountries => 'Search rules',
            self::AppName, self::MetaDescription, self::MetaKeywords => 'Site identity',
            self::MapStyle => 'Map',
            self::HomeDealsLimit, self::HomePopularLimit => 'Homepage',
            self::ProfileName, self::ProfileEmail, self::ProfileRole, self::ProfileAvatar => 'Profile',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MinConnectMinutes => 'Shortest connection (minutes)',
            self::MaxConnectMinutes => 'Longest connection (minutes)',
            self::MaxStops => 'Maximum stops',
            self::MaxDetourRatio => 'Maximum detour ratio',
            self::MinDetourKm => 'Minimum detour floor (km)',
            self::NightFromHour => 'Night layover starts at (hour)',
            self::NightToHour => 'Night layover ends at (hour)',
            self::GulfCountries => 'Gulf countries',
            self::AppName => 'Site name',
            self::MetaDescription => 'Meta description',
            self::MetaKeywords => 'Meta keywords',
            self::MapStyle => 'Map style',
            self::HomeDealsLimit => 'Travel deals cards',
            self::HomePopularLimit => 'Popular flights cards',
            self::ProfileName => 'Name',
            self::ProfileEmail => 'Email address',
            self::ProfileRole => 'Role',
            self::ProfileAvatar => 'Avatar URL',
        };
    }

    /**
     * Why the field exists, shown beside it -- so raising `max_stops` is a
     * choice made with the same measurement behind it, not a guess.
     */
    public function reason(): string
    {
        return match ($this) {
            self::MinConnectMinutes => 'Shortest wait the search allows between two flights.',
            self::MaxConnectMinutes => 'Longest wait still offered as one itinerary.',
            self::MaxStops => 'Across 211 airports, two stops costs 250-700ms to rank; across 50 it '
                . 'was ~2s and could time out. Re-measure before raising this if the network narrows.',
            self::MaxDetourRatio => 'How far an itinerary may fly, as a multiple of the direct distance.',
            self::MinDetourKm => 'Floor under that cap, so a short trip can still connect at all.',
            self::NightFromHour => 'Hour (0-23) a layover starts counting as overnight.',
            self::NightToHour => 'Hour (0-23) a layover stops counting as overnight.',
            self::GulfCountries => 'Two-letter country codes behind the "no layover in the Gulf" '
                . 'toggle, one per line.',
            self::AppName => 'Shown in the browser tab and the panel\'s own header.',
            self::MetaDescription => 'The line a search result shows under the page title.',
            self::MetaKeywords => 'One per line. Most search engines ignore this today.',
            self::MapStyle => 'A Mapbox style, written as "username/style-id".',
            self::HomeDealsLimit => 'How many cards "Travel deals under $X" shows on the homepage.',
            self::HomePopularLimit => 'How many cards "Popular flights near you" shows per tab.',
            self::ProfileName => 'Shown in the topbar user menu and the public site\'s footer credit.',
            self::ProfileEmail => 'Shown in the topbar user menu and the page \'author\' meta tag.',
            self::ProfileRole => 'Shown under the name in the topbar user menu.',
            self::ProfileAvatar => 'A photo URL -- this app has no image upload, so a link is what it takes.',
        };
    }

    /**
     * Whether the field is a list, one value per line, rather than a single
     * value on one line.
     */
    public function isList(): bool
    {
        return match ($this) {
            self::GulfCountries, self::MetaKeywords => true,
            default => false,
        };
    }

    /**
     * The current value, ready to sit in the form -- a number as a string, a
     * list one entry per line.
     */
    public function format(mixed $value): string
    {
        if ($this->isList()) {
            /** @var list<string> $value */
            return implode("\n", $value);
        }

        if ($this === self::MaxDetourRatio) {
            /** @var float $value */
            return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');
        }

        if (
            $this === self::AppName || $this === self::MetaDescription || $this === self::MapStyle
            || $this === self::ProfileName || $this === self::ProfileEmail
            || $this === self::ProfileRole || $this === self::ProfileAvatar
        ) {
            /** @var string $value */
            return $value;
        }

        /** @var int $value */
        return (string) $value;
    }

    /**
     * What was posted, turned into the shape this key is stored as.
     *
     * Parsing and validating are separate on purpose: a form that could not
     * be parsed at all ("twelve" for a stop count) and one that parsed to a
     * value that makes no sense (a negative one) are different failures, and
     * only the second can point at what a good value looks like.
     */
    public function parse(string $raw): mixed
    {
        if ($this->isList()) {
            $lines = array_values(array_filter(array_map(trim(...), explode("\n", $raw)), static fn(string $v): bool => $v !== ''));

            return $this === self::GulfCountries ? array_map(strtoupper(...), $lines) : $lines;
        }

        return match ($this) {
            self::MaxDetourRatio => filter_var(trim($raw), FILTER_VALIDATE_FLOAT),
            self::AppName, self::MetaDescription, self::MapStyle,
            self::ProfileName, self::ProfileEmail, self::ProfileRole, self::ProfileAvatar => trim($raw),
            default => filter_var(trim($raw), FILTER_VALIDATE_INT),
        };
    }

    /**
     * What is wrong with a parsed value, or null when it is fine to store.
     */
    public function invalidBecause(mixed $parsed): ?string
    {
        return match ($this) {
            self::MinConnectMinutes, self::MaxConnectMinutes, self::MinDetourKm
                => !is_int($parsed) || $parsed < 0 ? 'Needs a whole number, zero or more.' : null,
            self::MaxStops => !is_int($parsed) || $parsed < 0 ? 'Needs a whole number, zero or more.' : null,
            self::HomeDealsLimit, self::HomePopularLimit
                => !is_int($parsed) || $parsed < 1 ? 'Needs a whole number, one or more.' : null,
            self::MaxDetourRatio => !is_float($parsed) || $parsed <= 1.0 ? 'Needs a number greater than 1.' : null,
            self::NightFromHour, self::NightToHour
                => !is_int($parsed) || $parsed < 0 || $parsed > 23 ? 'Needs an hour from 0 to 23.' : null,
            self::GulfCountries => self::firstNotTwoLetters($parsed),
            self::AppName, self::MetaDescription, self::MapStyle,
            self::ProfileName, self::ProfileRole
                => $parsed === '' ? 'Cannot be empty.' : null,
            self::MetaKeywords => null,
            self::ProfileEmail => self::invalidEmail($parsed),
            self::ProfileAvatar => self::invalidUrl($parsed),
        };
    }

    private static function invalidEmail(mixed $parsed): ?string
    {
        if (!is_string($parsed) || $parsed === '') {
            return 'Cannot be empty.';
        }

        return filter_var($parsed, FILTER_VALIDATE_EMAIL) === false ? 'Enter a valid email address.' : null;
    }

    private static function invalidUrl(mixed $parsed): ?string
    {
        if (!is_string($parsed) || $parsed === '') {
            return 'Cannot be empty.';
        }

        return filter_var($parsed, FILTER_VALIDATE_URL) === false ? 'Enter a valid URL.' : null;
    }

    private static function firstNotTwoLetters(mixed $parsed): ?string
    {
        if (!is_array($parsed)) {
            return 'Needs a list of two-letter country codes.';
        }

        foreach ($parsed as $code) {
            if (!is_string($code) || preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                return sprintf('"%s" is not a two-letter country code.', is_string($code) ? $code : gettype($code));
            }
        }

        return null;
    }
}
