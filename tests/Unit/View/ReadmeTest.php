<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

    public function testRendersWhatTheProjectSaysAboutItself(): void
    {
        self::assertStringContainsString('The Air Trips Builder is an application', $this->html);
        self::assertStringContainsString('About The Project', $this->html);
        self::assertStringContainsString('Features', $this->html);
        self::assertStringContainsString('Flight Search', $this->html);
    }

    public function testRendersTheDeveloperHalfToo(): void
    {
        // The whole README is the page. Whoever reads /about on a coding
        // assessment is evaluating the project, so how it is installed and what
        // its CLI does is the substance rather than clutter.
        self::assertStringContainsString('Installation', $this->html);
        self::assertStringContainsString('git clone', $this->html);
        self::assertStringContainsString('Available Commands', $this->html);
        self::assertStringContainsString('License', $this->html);
    }

    public function testStopsAtAnEndMarkerWhenThereIsOne(): void
    {
        // No end marker is in the README today, but the slice still honours one
        // so the page can be cut short without touching this code.
        $method = new ReflectionMethod(Readme::class, 'slice');

        $sliced = (string) $method->invoke(
            new Readme(),
            "intro\n<!-- about:start -->\nkept\n<!-- about:end -->\ndropped\n",
        );

        self::assertStringContainsString('kept', $sliced);
        self::assertStringNotContainsString('dropped', $sliced);
        self::assertStringNotContainsString('intro', $sliced);
    }

    public function testRendersCodeBlocksAndTablesFromTheDeveloperHalf(): void
    {
        // Both appear only in the second half of the README, so these also
        // confirm the whole file is being converted.
        self::assertStringContainsString('<pre>', $this->html);
        self::assertStringContainsString('<code', $this->html);
        self::assertStringContainsString('<table>', $this->html);
    }

    public function testCarriesTheReferenceDefinitionsAcrossTheSlice(): void
    {
        // The definitions live at the very bottom of the file while the links
        // using them are spread through it, so a slice that dropped them would
        // leave the markup printed literally instead of resolved.
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

    /**
     * The page's heading is the page's, and there is only one of it.
     *
     * The README's own title sits above the start marker, so the document
     * begins at its first paragraph. Left in, it put a second <h1> immediately
     * under the page's -- naming the project where the page had just said what
     * the page is. The marker is one line in a file anybody may edit, which is
     * why this is asserted rather than trusted.
     */
    public function testTheProjectTitleIsLeftToThePage(): void
    {
        self::assertStringNotContainsString('<h1', $this->html);
    }

    public function testEveryHeadingLevelTheReadmeUsesIsStyled(): void
    {
        // The README drives this page, so it can grow a heading level at any
        // time. An unstyled one does not fall back harmlessly: Bootstrap sizes
        // h4 at 1.5rem, larger than the h2 above it, so an unstyled h4 read as
        // the biggest heading on the page and inverted the hierarchy.
        preg_match_all('/<h([1-6])\b/', $this->html, $used);

        $levels = array_unique(array_map('intval', $used[1]));

        self::assertNotEmpty($levels, 'The rendered README should contain headings');

        $css = (string) file_get_contents(__DIR__ . '/../../../frontend/css/main.css');

        foreach ($levels as $level) {
            self::assertMatchesRegularExpression(
                // Either prefix will do, and which one depends on the level:
                // the page shares its shell with /help, so the levels prose has
                // in common are styled under `.article__body` and only the ones
                // markdown alone reaches are left to `.readme`.
                //
                // The selector has to end at the heading -- `{` for a rule of
                // its own or `,` for one shared with another level. A looser
                // match would be satisfied by `.readme h4 + p`, which styles
                // the paragraph after the heading and not the heading itself.
                sprintf('/^(?:\.readme|\.article__body) h%d\s*[,{]/m', $level),
                $css,
                sprintf('The README uses <h%1$d> but nothing in main.css styles it', $level),
            );
        }
    }

    public function testReportsWhenTheFileLastChanged(): void
    {
        $stamp = new Readme()->updatedAt();

        self::assertNotNull($stamp);
        self::assertGreaterThan(0, $stamp);
    }
}
