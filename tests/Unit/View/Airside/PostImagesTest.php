<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View\Airside;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;
use TripBuilder\View\Airside\PostImages;
use TripBuilder\View\Markdown;

/**
 * Images inside a post body.
 *
 * The fixture is 102 bytes of PNG written out as base64 rather than drawn,
 * because CI installs `mysqli`, `pdo_mysql`, `curl` and `mbstring` and this
 * way it does not matter whether `gd` came along with them. 24x9 so the
 * dimensions it asserts could not be a coincidence.
 */
final class PostImagesTest extends TestCase
{
    private const string FIXTURE = 'zzp-test-fixture.png';

    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAABgAAAAJCAIAAACnn3uRAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAA'
        . 'GElEQVQokWM8cekOAzUAE1VMGTVopBsEAGXaAoh6w0xbAAAAAElFTkSuQmCC';

    protected function setUp(): void
    {
        file_put_contents(self::path(), (string) base64_decode(self::PNG, true));
    }

    protected function tearDown(): void
    {
        @unlink(self::path());
    }

    private static function path(): string
    {
        return Helper::getRootDir() . '/' . PostImages::DIRECTORY . '/' . self::FIXTURE;
    }

    /*
    |--------------------------------------------------------------------------
    | What the body writes, and what the page gets
    |--------------------------------------------------------------------------
    */

    public function testAFileNameBecomesAPath(): void
    {
        $html = Markdown::toPostHtml('![A wing](' . self::FIXTURE . ')');

        self::assertStringContainsString('src="/frontend/img/airside/' . self::FIXTURE . '"', $html);
        self::assertStringContainsString('alt="A wing"', $html);
    }

    /**
     * Dimensions come off the file, so the page does not reflow as it loads.
     */
    public function testTheImageCarriesTheSizeTheFileActuallyIs(): void
    {
        $html = Markdown::toPostHtml('![A wing](' . self::FIXTURE . ')');

        self::assertStringContainsString('width="24"', $html);
        self::assertStringContainsString('height="9"', $html);
    }

    /**
     * A file that is not there gets no dimensions rather than invented ones.
     *
     * Attributes that disagree with the file are worse than none: the browser
     * reserves the wrong box and the page jumps anyway, later and by a
     * different amount. The importer refuses this case, so it should not
     * reach a page -- this is what happens if one ever does.
     */
    public function testAMissingFileGetsNoDimensions(): void
    {
        $html = Markdown::toPostHtml('![Gone](not-a-real-file.png)');

        self::assertStringNotContainsString('width=', $html);
        self::assertStringContainsString('src="/frontend/img/airside/not-a-real-file.png"', $html);
    }

    public function testEveryBodyImageIsLazy(): void
    {
        self::assertStringContainsString('loading="lazy"', Markdown::toPostHtml('![A wing](' . self::FIXTURE . ')'));
    }

    /*
    |--------------------------------------------------------------------------
    | Figures
    |--------------------------------------------------------------------------
    */

    public function testAnImageAloneInAParagraphBecomesAFigure(): void
    {
        $html = Markdown::toPostHtml('![A wing](' . self::FIXTURE . ')');

        self::assertStringContainsString('<figure>', $html);
        // `<p>` cannot contain `<figure>`; a browser given one repairs it by
        // moving the figure out and leaving an empty paragraph behind.
        self::assertStringNotContainsString('<p><figure>', $html);
    }

    public function testATitleBecomesACaption(): void
    {
        $html = Markdown::toPostHtml('![A wing](' . self::FIXTURE . ' "Somewhere over Greenland")');

        self::assertStringContainsString('<figcaption>Somewhere over Greenland</figcaption>', $html);
    }

    /**
     * An image in a sentence is not a figure.
     *
     * The distinction the two renderers have to agree on: a picture with words
     * around it stays inline, in its paragraph, with no caption.
     */
    public function testAnImageWithWordsAroundItStaysInline(): void
    {
        $html = Markdown::toPostHtml('Look at ![A wing](' . self::FIXTURE . ') here.');

        self::assertStringNotContainsString('<figure>', $html);
        self::assertStringContainsString('<p>Look at <img', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | What the importer reads
    |--------------------------------------------------------------------------
    */

    public function testTheBodysImagesAreFoundInOrder(): void
    {
        self::assertSame(
            ['a.jpg', 'b.jpg'],
            PostImages::inBody("Text ![one](a.jpg) inline.\n\n![two](b.jpg \"A caption\")"),
        );
    }

    /**
     * A post about writing markdown is exactly the post somebody will file.
     *
     * Parsed rather than pattern-matched, so an example inside a code block is
     * a line about markdown and not a request for a file that does not exist.
     */
    public function testAnImageInsideACodeBlockIsNotARequestForAFile(): void
    {
        $markdown = "Write it like this:\n\n```\n![alt](never-committed.jpg)\n```\n\n    ![alt](nor-this.jpg)";

        self::assertSame([], PostImages::inBody($markdown));
    }

    /*
    |--------------------------------------------------------------------------
    | And the shared converter is untouched
    |--------------------------------------------------------------------------
    */

    public function testHelpAndAboutStillRenderAPlainImage(): void
    {
        $html = Markdown::toHtml('![A wing](' . self::FIXTURE . ')');

        self::assertStringContainsString('<img src="' . self::FIXTURE . '"', $html);
        self::assertStringNotContainsString('<figure>', $html);
        self::assertStringNotContainsString('loading=', $html);
    }
}
