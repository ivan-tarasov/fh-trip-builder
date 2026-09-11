<?php

declare(strict_types=1);

namespace TripBuilder\Aws;

/**
 * What this project asks of a bucket.
 *
 * An interface over one implementation, which is usually a smell. It is here
 * because the behaviour worth testing is what these callers do *not* do --
 * seven variants with six already up is one PUT, and a sweep that has
 * miscounted what is referenced deletes the only copy of a photograph. Proving
 * either against `S3` would mean proving it against the network.
 *
 * Two callers and four methods: the uploader uses the first two, the sweep the
 * last two. One interface rather than two because they are one bucket, and a
 * fake that has to answer all four is less code than a second name for it.
 */
interface ObjectStore
{
    /** A year, and never revalidated. Safe only because names carry a hash. */
    public const string IMMUTABLE = 'public, max-age=31536000, immutable';

    public function has(string $key): bool;

    public function put(
        string $key,
        string $contents,
        string $contentType,
        string $cacheControl = self::IMMUTABLE,
    ): void;

    /** @return list<string> */
    public function keysUnder(string $prefix): array;

    public function delete(string $key): void;
}
