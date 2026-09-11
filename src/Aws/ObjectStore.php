<?php

declare(strict_types=1);

namespace TripBuilder\Aws;

/**
 * The two things the uploader asks of a bucket.
 *
 * An interface over one implementation, which is usually a smell. It is here
 * because the behaviour worth testing in `PostImageUploader` is what it does
 * *not* send -- seven variants, six already up, one PUT -- and proving that
 * against `S3` would mean proving it against the network.
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
}
