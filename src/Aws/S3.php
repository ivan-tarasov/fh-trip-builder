<?php

declare(strict_types=1);

namespace TripBuilder\Aws;

use RuntimeException;

/**
 * The two S3 calls this project makes: put an object, ask whether one is there.
 *
 * Deliberately not a client for S3. Listing, deleting, multipart and presigned
 * URLs are all absent because nothing here needs them, and each one would drag
 * in XML parsing or a second signing mode. If two of those ever arrive, this
 * is the file to delete in favour of `async-aws/s3`.
 */
final readonly class S3 implements ObjectStore
{
    private const int TIMEOUT_SECONDS = 60;
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private string $bucket,
        private string $region,
        private Signature $signature,
    ) {}

    /**
     * Built from the environment, which is where a write credential belongs.
     *
     * Missing keys are an error and not an empty string: an unsigned PUT is
     * refused by S3 with the same `AccessDenied` as a wrong one, so a caller
     * that forgot to set them would read the answer as a permissions problem
     * and go looking in IAM.
     */
    public static function fromEnvironment(): self
    {
        $required = ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BUCKET', 'AWS_REGION'];
        $missing = array_values(array_filter(
            $required,
            static fn(string $key): bool => ($_ENV[$key] ?? '') === '',
        ));

        if ($missing !== []) {
            throw new RuntimeException('Not set in the environment: ' . implode(', ', $missing) . '.');
        }

        $bucket = (string) $_ENV['AWS_BUCKET'];
        $region = (string) $_ENV['AWS_REGION'];

        // A dot in the name makes the virtual-hosted endpoint one label deeper
        // than the `*.s3.<region>.amazonaws.com` certificate covers, so every
        // request fails at TLS rather than at S3, with an error that says
        // nothing about buckets. Buckets cannot be renamed, so this is a
        // refusal and not a warning.
        if (str_contains($bucket, '.')) {
            throw new RuntimeException(
                sprintf('The bucket `%s` has a dot in its name, which breaks TLS on the S3 endpoint.', $bucket),
            );
        }

        return new self($bucket, $region, new Signature(
            (string) $_ENV['AWS_ACCESS_KEY_ID'],
            (string) $_ENV['AWS_SECRET_ACCESS_KEY'],
            $region,
            's3',
        ));
    }

    /** Whether the key is already in the bucket, so identical bytes are not sent twice. */
    public function has(string $key): bool
    {
        return $this->send('HEAD', $key, '', [])[0] === 200;
    }

    public function put(
        string $key,
        string $contents,
        string $contentType,
        string $cacheControl = ObjectStore::IMMUTABLE,
    ): void {
        [$status, $body] = $this->send('PUT', $key, $contents, [
            'content-type' => $contentType,
            'cache-control' => $cacheControl,
        ]);

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                'S3 answered %d for `%s`%s',
                $status,
                $key,
                self::explain($body),
            ));
        }
    }

    /**
     * Every key under a prefix, following the continuation tokens to the end.
     *
     * The one operation here that answers in XML and the one that answers in
     * pages: S3 returns at most a thousand keys and a token for the rest, so a
     * caller that read the first page would quietly sweep against a partial
     * picture. Paging is not an optimisation here, it is correctness.
     *
     * @return list<string>
     */
    public function keysUnder(string $prefix, int $pageSize = 1000): array
    {
        $keys = [];
        $token = null;

        do {
            // `max-keys` is S3's own ceiling at its default, and an argument
            // only so the paging can be made to happen on demand. A bucket
            // with fewer than a thousand objects would otherwise never take
            // the second time round this loop, and the one path that matters
            // would go unrun until the day it mattered.
            $query = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => (string) $pageSize];

            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            [$status, $body] = $this->send('GET', '', '', [], $query);

            if ($status !== 200) {
                throw new RuntimeException(sprintf(
                    'S3 answered %d listing `%s`%s',
                    $status,
                    $prefix,
                    self::explain($body),
                ));
            }

            $xml = simplexml_load_string($body);

            if ($xml === false) {
                throw new RuntimeException('S3 answered a listing this could not read.');
            }

            foreach ($xml->Contents as $object) {
                $keys[] = (string) $object->Key;
            }

            $token = isset($xml->NextContinuationToken) ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null);

        return $keys;
    }

    /**
     * Remove one object.
     *
     * S3 answers 204 whether or not the key was there, which is the right
     * behaviour to pass on: a sweep that has already decided a key is
     * unreferenced does not care to learn it was already gone.
     */
    public function delete(string $key): void
    {
        [$status, $body] = $this->send('DELETE', $key, '', []);

        if ($status !== 204 && $status !== 200) {
            throw new RuntimeException(sprintf(
                'S3 answered %d deleting `%s`%s',
                $status,
                $key,
                self::explain($body),
            ));
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array{0: int, 1: string}
     */
    private function send(string $method, string $key, string $payload, array $headers, array $query = []): array
    {
        $host = sprintf('%s.s3.%s.amazonaws.com', $this->bucket, $this->region);
        $path = '/' . ltrim($key, '/');
        $canonical = Signature::canonicalQuery($query);

        $signed = $this->signature->headers(
            $method,
            $path,
            $canonical,
            ['host' => $host] + $headers,
            $payload,
        );

        $handle = curl_init('https://' . $host . $path . ($canonical === '' ? '' : '?' . $canonical));

        if ($handle === false) {
            throw new RuntimeException('Could not start a request to S3.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            // A redirect from S3 means the region is wrong, and following it
            // would send a signature made for a host it was not made for.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_map(
                static fn(string $name, string $value): string => $name . ': ' . $value,
                array_keys($signed),
                $signed,
            ),
        ];

        // Assigned and never spread into the literal above. These constants are
        // integers, and the spread operator renumbers integer keys -- so
        // `...[CURLOPT_POSTFIELDS => $body]` sets whatever option happens to sit
        // at the next index instead, curl sends no body, and S3 answers 411.
        if ($payload !== '') {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if ($error !== '') {
            throw new RuntimeException('The request to S3 failed: ' . $error);
        }

        return [$status, is_string($body) ? $body : ''];
    }

    /** S3 puts the useful half of a refusal in an XML `Code`, not in the status. */
    private static function explain(string $body): string
    {
        $matched = preg_match('|<Code>([^<]+)</Code>|', $body, $found) === 1;

        return $matched ? ' (' . $found[1] . ').' : '.';
    }
}
