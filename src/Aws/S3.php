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
     * @param array<string, string> $headers
     * @return array{0: int, 1: string}
     */
    private function send(string $method, string $key, string $payload, array $headers): array
    {
        $host = sprintf('%s.s3.%s.amazonaws.com', $this->bucket, $this->region);
        $path = '/' . ltrim($key, '/');

        $signed = $this->signature->headers(
            $method,
            $path,
            '',
            ['host' => $host] + $headers,
            $payload,
        );

        $handle = curl_init('https://' . $host . $path);

        if ($handle === false) {
            throw new RuntimeException('Could not start a request to S3.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_POSTFIELDS => $payload,
            // A redirect from S3 means the region is wrong, and following it
            // would send a signature made for a host it was not made for.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_map(
                static fn(string $name, string $value): string => $name . ': ' . $value,
                array_keys($signed),
                $signed,
            ),
        ]);

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
