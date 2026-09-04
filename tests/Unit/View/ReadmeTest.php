<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\View\Readme;

/**
 * Runs against the project's real README, because the contract being tested is
 * with that file: the /about page shows part of it, and the markers are how the
 * part is chosen. A fixture would pass while the real page broke.
 */
final class ReadmeTest extends TestCase
{
    private string $html;

    protected function setUp(): void
    {
        $html = new Readme()->html();

        self::assertNotNull($html, 'The README should be readable from the repository root');

        $this->html = $html;
    }

    public function testRendersTheProductHalfOfTheReadme(): void
    {
        self::assertStringContainsString('<h1>Trip Builder</h1>', $this->html);
        self::assertStringContainsString('About The Project', $this->html);
        self::assertStringContainsString('Features', $this->html);
        self::assertStringContainsString('Flight Search', $this->html);
    }

    public function testStopsAtTheEndMarker(): void
    {
        // Everything past the marker is for somebody with a terminal. If this
        // fails the page has grown a "git clone" section.
        self::assertStringNotContainsString('Installation', $this->html);
        self::assertStringNotContainsString('git clone', $this->html);
        self::assertStringNotContainsString('Available Commands', $this->html);
    }

    public function testCarriesTheReferenceDefinitionsAcrossTheSlice(): void
    {
        // The definitions live below the end marker while the links using them
        // are above it, so a slice that dropped them would leave the markup
        // printed literally instead of resolved.
        self::assertStringNotContainsString('][', $this->html);
        self::assertMatchesRegularExpression('/<a href="[^"]+">FlightHub<\/a>/', $this->html);
        self::assertStringContainsString('img.shields.io', $this->html);
    }

    public function testDropsCommentsInsteadOfPrintingThem(): void
    {
        // Raw HTML is escaped rather than passed through, so a comment left in
        // the slice would reach the page as visible text.
        self::assertStringNotContainsString('about:start', $this->html);
        self::assertStringNotContainsString('about:end', $this->html);
        self::assertStringNotContainsString('&lt;!--', $this->html);
    }

    public function testKeepsTheMixedContentScreenshotOffThePage(): void
    {
        // The screenshot is served over http:// from an S3 website endpoint,
        // which a browser blocks on the https site. It sits above the start
        // marker for that reason, so nothing here should be insecure.
        self::assertStringNotContainsString('http://', str_replace('https://', '', $this->html));
    }

    public function testReportsWhenTheFileLastChanged(): void
    {
        $stamp = new Readme()->updatedAt();

        self::assertNotNull($stamp);
        self::assertGreaterThan(0, $stamp);
    }
}
