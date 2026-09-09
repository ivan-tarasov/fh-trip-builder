<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * Whether the visitor has said yes to analytics.
 *
 * The site loads Google Analytics and Yandex Metrika, and Metrika is
 * configured with Webvisor -- it records mouse movement, scrolling, clicks and
 * typing and plays the session back. Loading that before anybody has agreed to
 * it would make the notice decoration, so nothing loads until this says
 * granted. No answer is not a yes.
 *
 * `$_COOKIE` is read here rather than through the request object, for the
 * reason LayoutData::subscribeNotice() reads `$_SESSION` directly: this is
 * layout-scoped state that every page needs and no controller has any business
 * passing down. The name and the two values are constants because the browser
 * writes this cookie -- global.js takes all three off the notice's own data
 * attributes, so there is still only one spelling of them.
 */
final class Consent
{
    public const string COOKIE = 'tb_cookie_consent';
    public const string GRANTED = 'granted';
    public const string DENIED = 'denied';

    /** A year, like the other two `tb_` cookies. */
    public const int MAX_AGE = 60 * 60 * 24 * 365;

    public static function granted(): bool
    {
        return self::answer() === self::GRANTED;
    }

    /**
     * Asked and answered, either way. What decides whether the notice shows.
     */
    public static function answered(): bool
    {
        return in_array(self::answer(), [self::GRANTED, self::DENIED], true);
    }

    private static function answer(): ?string
    {
        $value = $_COOKIE[self::COOKIE] ?? null;

        return is_string($value) ? $value : null;
    }
}
