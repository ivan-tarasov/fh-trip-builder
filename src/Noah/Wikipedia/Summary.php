<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Wikipedia;

/**
 * One Wikipedia REST summary lookup, with the two things every caller of
 * it needs regardless of what it is looking up: Wikipedia's own IP-based
 * rate limit honored via `Retry-After`, and its own disambiguation signal
 * read off `type` rather than guessed at from the response text.
 *
 * Extracted from `Noah\Cities\Content` (C10, #395) once a second caller
 * (`Noah\Countries\Content`, C17 #409) needed the identical shape -- see
 * C16 (#408) for why this was not done with only one caller to check it
 * against.
 *
 * What is *not* here, on purpose: the photo pipeline (S3, the resolution
 * bump, `guessKey()`) is city-specific machinery the country command does
 * not want yet (its own funnel entry is explicit: description-first), and
 * the staleness query is table-specific SQL each caller's own repository
 * already owns. Only the fetch/backoff/disambiguation-detection logic is
 * shared -- each caller keeps its own title-override map too, since a
 * city and a country collide with different senses of a name.
 */
final readonly class Summary
{
    private const string ENDPOINT = 'https://en.wikipedia.org/api/rest_v1/page/summary/%s';

    /** Long enough for a slow response, short enough not to hang a whole run on one lookup. */
    private const int TIMEOUT_SECONDS = 10;
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    /** A `Retry-After` this will not sit and wait longer than. */
    private const int MAX_BACKOFF_SECONDS = 15;

    /**
     * A sentinel `lookUp()` can return instead of a real answer: "could
     * not ask (or could not use what came back) this time" -- a string
     * rather than a real tri-state so a caller can `match` on one type
     * instead of juggling `null` for two different meanings alongside a
     * confirmed no-answer.
     */
    public const string TRANSIENT = 'transient';

    /** @param non-empty-string $userAgent */
    public function __construct(private string $userAgent) {}

    /**
     * One retry, and only for a `429` -- live-measured on `cities:content`,
     * this is a real, IP-based limit Wikipedia enforces and names with its
     * own `Retry-After` header, not noise: a batch of requests that ran
     * clean in isolation started failing consistently once it hit some
     * request count, and every failure was a `429` carrying that header.
     * Waiting it out once is worth doing since the answer says exactly how
     * long; a second `429` after that is left transient rather than
     * waited out again, so one throttled lookup cannot stall a whole run.
     *
     * `$title` is whatever the caller has already decided to ask for --
     * resolving a plain name that lands on a disambiguation page to the
     * real title is the caller's own business, since different entity
     * types collide with different senses of a name (a city with a
     * president or a state, a country with a bird or a person's name).
     *
     * @return self::TRANSIENT|array{source: string|null, extract: string|null, ambiguous: bool}
     */
    public function lookUp(string $title): array|string
    {
        $url = sprintf(self::ENDPOINT, rawurlencode(str_replace(' ', '_', $title)));

        $response = $this->request($url);

        if ($response['status'] === 429 && $response['retryAfter'] !== null) {
            sleep(min($response['retryAfter'], self::MAX_BACKOFF_SECONDS));
            $response = $this->request($url);
        }

        // A real "no such page" -- confirmed, not transient.
        if ($response['status'] === 404) {
            return ['source' => null, 'extract' => null, 'ambiguous' => false];
        }

        if ($response['body'] === null || $response['status'] !== 200) {
            return self::TRANSIENT;
        }

        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            return self::TRANSIENT;
        }

        // Wikipedia's own signal for "this title is not one article" -- a
        // disambiguation page's own `extract` is a list of what the name
        // could mean ("Washington most commonly refers to: ..."), not a
        // summary of any real place, so it is worth no more than the
        // thumbnail it also does not have.
        $ambiguous = ($data['type'] ?? null) === 'disambiguation';

        /** @var mixed $thumbnail */
        $thumbnail = $ambiguous ? null : ($data['thumbnail']['source'] ?? null);
        /** @var mixed $extract */
        $extract = $ambiguous ? null : ($data['extract'] ?? null);

        return [
            'source' => is_string($thumbnail) ? $thumbnail : null,
            'extract' => is_string($extract) && $extract !== '' ? $extract : null,
            'ambiguous' => $ambiguous,
        ];
    }

    /** @return array{status: int, body: string|null, retryAfter: int|null} */
    private function request(string $url): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return ['status' => 0, 'body' => null, 'retryAfter' => null];
        }

        $retryAfter = null;

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // The only header this reads. A closure over $retryAfter rather
            // than a second curl_getinfo() call: libcurl hands headers to
            // this as they arrive, not the response body's own parser.
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^retry-after:\s*(\d+)/i', $line, $match) === 1) {
                    $retryAfter = (int) $match[1];
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated
        // from 8.5, where it prints a notice over the caller's own output.

        return [
            'status' => $status,
            'body' => is_string($body) && $error === '' ? $body : null,
            'retryAfter' => $retryAfter,
        ];
    }
}
