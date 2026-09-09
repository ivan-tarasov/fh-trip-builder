<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * Which browser is voting on an article.
 *
 * There are no accounts in this app, so a vote has to be pinned to something,
 * and this is the something: a random token in a cookie, which becomes half of
 * the `article_votes` primary key. That key is what stops one reader deciding
 * which article the footer promotes -- see the note on the table.
 *
 * What it does not defend against, stated because a guard nobody has written
 * down is a guard somebody will later assume is stronger than it is: clearing
 * cookies gets a new token and therefore a second vote. That is proportionate
 * here. The alternative identifier is an IP address, and keeping one would be
 * worse for the reader, would have to be declared in a privacy policy this site
 * actually publishes, and would still be defeated by a phone leaving the house.
 * A thumbs-up on a help article is not worth more than that.
 *
 * Minted only when somebody votes. Reading an article gets no cookie, which is
 * why `current()` never writes and `identify()` is called from one place.
 *
 * `$_COOKIE` is read directly rather than through the request object, for the
 * reason Consent and Currency give: this is the same layout-scoped state that
 * no controller has any business threading downwards. Unlike those two, PHP
 * writes this cookie rather than JavaScript -- nothing in the browser needs to
 * read it -- so it follows RecentSearches instead, httponly included.
 */
final class Voter
{
    public const string COOKIE = 'tb_voter';

    /** A year, like every other `tb_` cookie. */
    private const int MAX_AGE = 60 * 60 * 24 * 365;

    /** 16 random bytes, hex encoded, which is what fills `voter char(32)`. */
    private const int BYTES = 16;

    /**
     * This browser's token, or null if it has never voted.
     *
     * Never writes. A reader who has not voted has no identity to record, and
     * handing every visitor a cookie so they might vote later is the sort of
     * thing the cookie notice would then have to be honest about.
     */
    public static function current(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? null;

        // A cookie is whatever the browser sent, so the format is checked and
        // not assumed. Anything else is treated as no token at all rather than
        // repaired: `voter` is char(32), and a longer value would be stored
        // truncated, which would silently merge two readers into one row.
        //
        // `\z` and not `$`, which is the whole reason this line has a test.
        // `$` also matches immediately before a trailing newline, so a cookie
        // ending "...f90\n" satisfied the pattern and was handed back with the
        // newline still on it -- thirty-three bytes for a column that holds
        // thirty-two.
        return is_string($token) && preg_match('/^[0-9a-f]{32}\z/', $token) === 1 ? $token : null;
    }

    /**
     * This browser's token, minting and sending one if it has none.
     *
     * Called when a vote is being recorded and nowhere else. The header goes
     * out before anything is echoed because index.php buffers output, the same
     * reason RecentSearches::remember() can set a cookie mid-render.
     */
    public static function identify(bool $secure): string
    {
        $token = self::current();

        if ($token !== null) {
            return $token;
        }

        $token = bin2hex(random_bytes(self::BYTES));

        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::MAX_AGE,
            'path' => '/',
            'samesite' => 'Lax',
            'secure' => $secure,
            // Read by PHP to know who voted, and by nothing in the browser.
            'httponly' => true,
        ]);

        // Set on the response, so `$_COOKIE` would not carry it until the next
        // request -- but the vote being recorded now needs it, and so does a
        // second call in the same request.
        $_COOKIE[self::COOKIE] = $token;

        return $token;
    }
}
