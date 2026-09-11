<?php

declare(strict_types=1);

namespace TripBuilder\Aws;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Signature Version 4, the scheme every AWS request is authenticated with.
 *
 * Written here rather than taken from a package because it is per-request and
 * not per-service: the same four fields sign S3, CloudFront and SES alike, so
 * a second service costs a caller and not a dependency. `aws/aws-sdk-php`
 * would carry all of AWS to spell one PUT.
 *
 * The scheme is HMAC-SHA256 applied four times to derive a key, then once more
 * over a description of the request. Nothing here is novel cryptography; what
 * it is, is exacting -- a byte out of place produces `SignatureDoesNotMatch`
 * and no clue as to which byte. That is why the two intermediate strings are
 * returned by their own methods: the tests read them, and a mismatch says
 * where it went wrong rather than only that it did.
 */
final readonly class Signature
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private string $accessKey,
        private string $secretKey,
        private string $region,
        private string $service,
    ) {}

    /**
     * Every header the request must carry, the Authorization one included.
     *
     * `host` has to be among those passed in: it is signed, so a request that
     * arrives at a different host than the one signed for is rejected, which
     * is the property that makes the signature worth anything.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function headers(
        string $method,
        string $path,
        string $query,
        array $headers,
        string $payload,
        ?DateTimeImmutable $at = null,
    ): array {
        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stamp = $at->format('Ymd\THis\Z');

        // S3 requires the payload hash as a header, and every service accepts
        // it, so it is set here rather than left to the caller to remember.
        $headers['x-amz-date'] = $stamp;
        $headers['x-amz-content-sha256'] = hash('sha256', $payload);

        $canonical = $this->canonicalRequest($method, $path, $query, $headers, $payload);
        $signed = self::signedHeaders($headers);
        $scope = $at->format('Ymd') . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $signature = hash_hmac(
            'sha256',
            $this->stringToSign($canonical, $stamp, $scope),
            $this->signingKey($at->format('Ymd')),
        );

        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $scope,
            $signed,
            $signature,
        );

        return $headers;
    }

    /**
     * The request reduced to the fields the signature covers.
     *
     * @param array<string, string> $headers
     */
    public function canonicalRequest(
        string $method,
        string $path,
        string $query,
        array $headers,
        string $payload,
    ): string {
        $lines = [];

        foreach (self::lowercased($headers) as $name => $value) {
            $lines[] = $name . ':' . $value;
        }

        return implode("\n", [
            strtoupper($method),
            self::canonicalPath($path),
            $query,
            implode("\n", $lines) . "\n",
            self::signedHeaders($headers),
            hash('sha256', $payload),
        ]);
    }

    public function stringToSign(string $canonicalRequest, string $stamp, string $scope): string
    {
        return implode("\n", [
            self::ALGORITHM,
            $stamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
    }

    /**
     * The key, derived from the secret by date, region and service in that
     * order, so a leaked signature is useless for another day or another
     * service. Raw output at every step but the caller's, which is hex.
     */
    public function signingKey(string $date): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    /**
     * Each segment encoded, the separators left alone.
     *
     * S3 is the exception to AWS's own rule here: every other service expects
     * the path normalised and encoded twice, and S3 expects it untouched and
     * encoded once. This does the S3 form, which is the only one used.
     */
    private static function canonicalPath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function lowercased(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $out[strtolower($name)] = trim($value);
        }

        ksort($out);

        return $out;
    }

    /** @param array<string, string> $headers */
    private static function signedHeaders(array $headers): string
    {
        return implode(';', array_keys(self::lowercased($headers)));
    }
}
