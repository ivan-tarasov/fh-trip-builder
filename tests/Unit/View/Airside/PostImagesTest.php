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
 * The fixture is only a name now. It used to be written to disk, because the
 * renderer measured the file to size it; A8.10 records sizes at import instead,
 * so a body image's dimensions arrive as an argument and no test needs a file
 * on disk to assert them. The base64 PNG stays for the author picture, which
 * is still found by looking.
 */
final class PostImagesTest extends TestCase
{
    private const string FIXTURE = 'zzp-test-fixture.png';

    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAABgAAAAJCAIAAACnn3uRAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAA'
        . 'GElEQVQokWM8cekOAzUAE1VMGTVopBsEAGXaAoh6w0xbAAAAAElFTkSuQmCC';

    private ?string $cdn = null;

    /**
     * The distribution is switched off for most of these, and that is the
     * point rather than convenience: `url()` answers differently depending on
     * whether there is one, and a test that inherits whatever the developer's
     * `.env` happens to say is testing the machine rather than the code. This
     * environment does configure one, which is how that was noticed.
     */
    protected function setUp(): void
    {
        $this->cdn = $_ENV['AWS_CLOUDFRONT'] ?? null;
        $_ENV['AWS_CLOUDFRONT'] = '';
    }

    protected function tearDown(): void
    {
        if ($this->cdn === null) {
            unset($_ENV['AWS_CLOUDFRONT']);
        } else {
            $_ENV['AWS_CLOUDFRONT'] = $this->cdn;
        }
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
     * Dimensions come from the table, so the page does not reflow as it loads.
     */
    public function testTheImageCarriesTheSizeRecordedForIt(): void
    {
        $html = Markdown::toPostHtml('![A wing](' . self::FIXTURE . ')', null, [self::FIXTURE => [24, 9]]);

        self::assertStringContainsString('width="24"', $html);
        self::assertStringContainsString('height="9"', $html);
    }

    /**
     * A file with no recorded size gets none rather than invented ones.
     *
     * Attributes that disagree with the file are worse than none: the browser
     * reserves the wrong box and the page jumps anyway, later and by a
     * different amount. The importer records every image it reads, so this
     * should not reach a page -- it is what happens if one ever does.
     */
    public function testAFileWithNoRecordedSizeGetsNoDimensions(): void
    {
        $html = Markdown::toPostHtml('![Gone](not-a-real-file.png)', null, [self::FIXTURE => [24, 9]]);

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

    /**
     * With a distribution, the same file is asked for from it.
     *
     * Under the `images` prefix the carrier logos and POI cards already use,
     * so Airside is a directory in the existing store rather than a second
     * store beside it.
     */
    public function testWithADistributionTheFileComesFromIt(): void
    {
        $_ENV['AWS_CLOUDFRONT'] = 'cdn.example.net';

        self::assertSame('//cdn.example.net/images/airside/wing.jpg', PostImages::url('wing.jpg'));
    }

    public function testWithoutOneTheStagingCopyIsUsed(): void
    {
        self::assertSame('/frontend/img/airside/wing.jpg', PostImages::url('wing.jpg'));
    }

    /*
    |--------------------------------------------------------------------------
    | The author's picture, and what stands in for one
    |--------------------------------------------------------------------------
    |
    | `posts.author` is the only record of who wrote a post, so the file is
    | found from the name rather than stored in a second column that would have
    | to be kept in step with the first.
    |
    */

    public function testAnAuthorWithNoPictureGetsNone(): void
    {
        self::assertNull(PostImages::author('Nobody In Particular'));
    }

    public function testAPictureIsFoundFromTheAuthorsName(): void
    {
        $path = Helper::getRootDir() . '/' . PostImages::DIRECTORY . '/authors/a-test-writer.png';

        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, (string) base64_decode(self::PNG, true));

        try {
            self::assertSame('/frontend/img/airside/authors/a-test-writer.png', PostImages::author('A Test Writer'));
        } finally {
            @unlink($path);
        }
    }

    /**
     * Initials are a design, not a missing image.
     *
     * Nothing in this repository ships an invented portrait, so every post
     * renders these until somebody drops a photograph in -- which means they
     * have to look deliberate for names of every shape.
     */
    public function testInitialsAreTheFirstAndLastWord(): void
    {
        self::assertSame('IT', PostImages::initials('Ivan Tarasov'));
        self::assertSame('JF', PostImages::initials('Jean-Luc de la Fontaine'));
    }

    public function testOneWordGivesOneLetter(): void
    {
        self::assertSame('C', PostImages::initials('Cher'));
    }

    public function testANameOfNothingStillDrawsSomething(): void
    {
        // The column is not nullable, so this cannot arrive from the importer
        // -- but a circle with nothing in it is worse than a question mark.
        self::assertSame('?', PostImages::initials('   '));
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
