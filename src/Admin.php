<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * The one operator, signed in or not.
 *
 * Scope, settled 2026-09-09 and not drifted from since: a single person, one
 * password hash in `.env`, a session flag, and nothing else. No accounts table,
 * no roles, no password reset, no "remember me" (A3.2, #100).
 *
 * That is not a shortcut, it is the honest size of the problem. There is one
 * operator and there always will be, and every part of a user system that is
 * not built here is a part that cannot be got wrong: no row to leak, no
 * enumeration to do, no reset mail to intercept, and no second account left
 * enabled after somebody leaves.
 *
 * What it costs is that the password can only be changed by editing `.env` and
 * that a lost one cannot be recovered. Both are correct for one person who owns
 * the server.
 */
final class Admin
{
    /** When this session signed in, which is also the flag that it did. */
    private const string SESSION_SINCE = 'admin_since';

    /** When it was last seen, for the idle timeout. */
    private const string SESSION_SEEN = 'admin_seen';

    /**
     * How long a session may sit untouched before it stops counting.
     *
     * An admin session is the one session on this site worth stealing, and a
     * laptop left open in a cafe is how it would be. Long enough to write an
     * article without being thrown out mid-sentence, short enough that it is
     * not still signed in tomorrow.
     */
    public const int IDLE_MINUTES = 60;

    /**
     * Whether this password is the one.
     *
     * `password_verify()` against a hash, never a comparison of two strings:
     * it is constant-time, and it carries its own algorithm and cost, so the
     * day a stronger default lands the old hashes still verify.
     *
     * **No hash configured means nobody signs in.** A server where this was
     * never set is a server nobody has decided to put a panel on, and the
     * alternative -- an empty password, or skipping the check -- is the way a
     * panel ends up open on the internet.
     */
    public static function verify(string $password): bool
    {
        $hash = Env::get(EnvKey::AdminPasswordHash);

        return $hash !== '' && $password !== '' && password_verify($password, $hash);
    }

    /**
     * Mark this session as the operator's.
     *
     * The id is regenerated first, and that is the whole of the defence
     * against session fixation: without it, an id planted in the browser before
     * sign-in is still the id afterwards, and whoever planted it is signed in
     * too. `true` so the old session is deleted rather than left behind.
     */
    public static function signIn(): void
    {
        // Guarded, because there is not always a session to regenerate: the
        // front controller starts one, a CLI run and a test do not, and
        // `session_regenerate_id()` on nothing is a warning rather than a
        // no-op.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_SINCE] = time();
        $_SESSION[self::SESSION_SEEN] = time();
    }

    /**
     * Whether the operator is signed in right now, and mark them seen.
     *
     * Reading this is what keeps a session alive, which is what makes the
     * timeout an *idle* one: every admin page asks, so somebody working is
     * never logged out and somebody who walked away is.
     */
    public static function isSignedIn(): bool
    {
        $since = $_SESSION[self::SESSION_SINCE] ?? null;
        $seen = $_SESSION[self::SESSION_SEEN] ?? null;

        if (!is_int($since) || !is_int($seen)) {
            return false;
        }

        if (time() - $seen > self::IDLE_MINUTES * 60) {
            self::signOut();

            return false;
        }

        $_SESSION[self::SESSION_SEEN] = time();

        return true;
    }

    /**
     * Forget the sign-in, keeping the session itself.
     *
     * The session carries a CSRF token and a list of saved flights that belong
     * to the browser rather than to the operator, so signing out drops two keys
     * rather than destroying everything the visitor had.
     */
    public static function signOut(): void
    {
        unset($_SESSION[self::SESSION_SINCE], $_SESSION[self::SESSION_SEEN]);
    }

    /** Whether a password has been set on this install at all. */
    public static function isConfigured(): bool
    {
        return Env::get(EnvKey::AdminPasswordHash) !== '';
    }
}
