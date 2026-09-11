<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Aws;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TripBuilder\Aws\Signature;

/**
 * Signature Version 4, against the vectors AWS publishes for it.
 *
 * The point of testing this against `get-vanilla` rather than against itself:
 * a signer that is wrong is not wrong loudly. S3 answers `SignatureDoesNotMatch`
 * and says nothing about which byte, so the only way to know this is right
 * before there is a credential to try it with is to reproduce an answer
 * somebody else computed.
 */
final class SignatureTest extends TestCase
{
    // The test suite's own fixed credential, which is not a secret: it is
    // published by AWS precisely so signers can be checked against it.
    private const string KEY = 'AKIDEXAMPLE';
    private const string SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    private const string STAMP = '20150830T123600Z';
    private const string SCOPE = '20150830/us-east-1/service/aws4_request';

    private function signature(string $region = 'us-east-1', string $service = 'service'): Signature
    {
        return new Signature(self::KEY, self::SECRET, $region, $service);
    }

    /** @return array<string, string> */
    private static function vanillaHeaders(): array
    {
        return ['Host' => 'example.amazonaws.com', 'X-Amz-Date' => self::STAMP];
    }

    public function testItBuildsTheCanonicalRequestForGetVanilla(): void
    {
        self::assertSame(
            "GET\n/\n\nhost:example.amazonaws.com\nx-amz-date:" . self::STAMP
            . "\n\nhost;x-amz-date\n"
            . 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $this->signature()->canonicalRequest('GET', '/', '', self::vanillaHeaders(), ''),
        );
    }

    public function testItReachesTheSignatureAwsPublishesForGetVanilla(): void
    {
        $signer = $this->signature();
        $canonical = $signer->canonicalRequest('GET', '/', '', self::vanillaHeaders(), '');

        self::assertSame(
            '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            hash_hmac(
                'sha256',
                $signer->stringToSign($canonical, self::STAMP, self::SCOPE),
                $signer->signingKey('20150830'),
            ),
        );
    }

    public function testTheStringToSignNamesTheAlgorithmTheStampAndTheScope(): void
    {
        $lines = explode("\n", $this->signature()->stringToSign('anything', self::STAMP, self::SCOPE));

        self::assertSame(Signature::ALGORITHM, $lines[0]);
        self::assertSame(self::STAMP, $lines[1]);
        self::assertSame(self::SCOPE, $lines[2]);
        self::assertSame(hash('sha256', 'anything'), $lines[3]);
    }

    public function testTheKeyIsBoundToTheDayTheRegionAndTheService(): void
    {
        $base = $this->signature()->signingKey('20150830');

        self::assertNotSame($base, $this->signature()->signingKey('20150831'));
        self::assertNotSame($base, $this->signature('eu-west-1')->signingKey('20150830'));
        self::assertNotSame($base, $this->signature('us-east-1', 's3')->signingKey('20150830'));
    }

    public function testItAddsTheDateAndThePayloadHashItself(): void
    {
        $headers = $this->signature()->headers(
            'PUT',
            '/images/airside/wing.3f9a2b1c.jpg',
            '',
            ['host' => 'bucket.s3.us-east-2.amazonaws.com'],
            'the bytes',
            new DateTimeImmutable('2026-09-11 18:44:51', new DateTimeZone('UTC')),
        );

        self::assertSame('20260911T184451Z', $headers['x-amz-date']);
        self::assertSame(hash('sha256', 'the bytes'), $headers['x-amz-content-sha256']);
    }

    public function testTheAuthorizationHeaderCarriesTheCredentialAndEverySignedName(): void
    {
        $headers = $this->signature('us-east-2', 's3')->headers(
            'PUT',
            '/images/airside/wing.jpg',
            '',
            ['host' => 'bucket.s3.us-east-2.amazonaws.com', 'content-type' => 'image/jpeg'],
            'bytes',
            new DateTimeImmutable('2026-09-11 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertStringStartsWith(Signature::ALGORITHM . ' Credential=' . self::KEY . '/20260911/us-east-2/s3/aws4_request,', $headers['Authorization']);

        // Sorted and lowercased, and every header actually sent is named --
        // a header that is signed but not listed, or listed but not signed,
        // is rejected.
        self::assertStringContainsString(
            'SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date,',
            $headers['Authorization'],
        );
    }

    public function testTheSameRequestOnADifferentDaySignsDifferently(): void
    {
        $signer = $this->signature('us-east-2', 's3');
        $arguments = ['PUT', '/k.jpg', '', ['host' => 'b.s3.us-east-2.amazonaws.com'], 'bytes'];

        self::assertNotSame(
            $signer->headers(...[...$arguments, new DateTimeImmutable('2026-09-11', new DateTimeZone('UTC'))])['Authorization'],
            $signer->headers(...[...$arguments, new DateTimeImmutable('2026-09-12', new DateTimeZone('UTC'))])['Authorization'],
        );
    }

    public function testItEncodesAPathSegmentButNotTheSeparators(): void
    {
        // A space in a file name is the case that matters: unencoded it ends
        // the request line, and encoded as `+` it signs one string and sends
        // another.
        self::assertStringContainsString(
            "\n/a/b%20c.jpg\n",
            $this->signature()->canonicalRequest('PUT', '/a/b c.jpg', '', ['host' => 'h'], ''),
        );
    }
}
