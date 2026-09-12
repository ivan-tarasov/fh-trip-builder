<?php

declare(strict_types=1);

namespace TripBuilder\Http;

/**
 * The response headers every page carries, and the browser behaviour they buy.
 *
 * In PHP rather than in `.htaccess`, which is what E8.1 (#143) proposed. Two
 * measured reasons. The origin is LiteSpeed, not Apache, and the only
 * `mod_headers` rule this project has targets font files that answer 404 in
 * production -- so there is no evidence the module is even loaded there, and
 * `<IfModule mod_headers.c>` fails by doing nothing, silently. And CI has no
 * web server, so a rule in that file could only ever be checked for being
 * spelled correctly, never for taking effect.
 *
 * Sent from the front controller, so they cover every response the app
 * produces, including the API. Static files under the document root do not get
 * them; documents are what these five protect.
 */
final readonly class SecurityHeaders
{
    /**
     * @return array<string, string>
     */
    public static function forRequest(Request $request): array
    {
        $headers = [
            // Stop the browser guessing a type other than the one sent. The
            // guess is the attack: a file served as text/plain that Internet
            // Explorer decided was HTML is the original reason this exists.
            'X-Content-Type-Options' => 'nosniff',

            // Nothing here is meant to be framed, and clickjacking needs a
            // frame. DENY rather than SAMEORIGIN because the site does not
            // frame itself either.
            'X-Frame-Options' => 'DENY',

            // Send the full URL to ourselves and only the origin to anyone
            // else. A search URL carries where somebody is flying and when.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            // Exactly the three the site does not use, and no more. A blanket
            // policy would take `clipboard-write` with it, and global.js uses
            // `navigator.clipboard` for the copy buttons on code blocks -- an
            // over-broad line here breaks them with no error anybody sees.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        // Only over HTTPS. A browser ignores this header on a plain-HTTP
        // response, but development is plain HTTP on a .localhost host, and a
        // browser that did honour it would refuse the local site until its
        // user found the right settings page.
        if ($request->isSecure()) {
            // A day, not a year. The mistake this header punishes is an
            // over-long one: every browser that has seen it refuses http://
            // for the whole max-age and there is no way to call it back. It
            // grows once the redirect in E8.2 (#144) has been live long
            // enough to trust, and `preload` is a separate decision after
            // that.
            $headers['Strict-Transport-Security'] = 'max-age=86400';
        }

        return $headers;
    }

    /**
     * Call before anything echoes: once output starts, headers are fixed.
     */
    public static function send(Request $request): void
    {
        foreach (self::forRequest($request) as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }
    }
}
