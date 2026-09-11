<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TripBuilder\Noah\Airside\Import;

/**
 * Reading an Airside post file, and refusing a broken one.
 *
 * The sibling of ArticlesImportTest, and the same reasoning: `parse()` is
 * static and takes a string because every judgement the import makes is there,
 * and none of it needs the disk or the table.
 *
 * What is new here, and what most of this file is about: a post carries a date
 * and may carry an image. Both are ways a file can be wrong that an article
 * could not be -- a date that does not exist, and a picture with nothing said
 * about what it shows.
 */
final class AirsideImportTest extends TestCase
{
    /** A post header, in the shape a committed file will carry. */
    private const string HEADER = "title: How to pick a seat\n"
        . "published: 2026-03-01\n"
        . "author: A Writer\n"
        . 'summary: Where you sit is decided before you board, and mostly by the fare.';

    public function testAGoodFileIsRead(): void
    {
        $parsed = Import::parse(self::file(self::HEADER, "## Up front\n\nRows one to five."));

        self::assertSame('How to pick a seat', $parsed['title']);
        self::assertSame('2026-03-01 00:00:00', $parsed['published_at']);
        self::assertSame('A Writer', $parsed['author']);
        self::assertSame("## Up front\n\nRows one to five.", $parsed['body']);
        self::assertNull($parsed['hero']);
        self::assertNull($parsed['hero_alt']);
    }

    /**
     * A date is written as a day and stored as a datetime.
     *
     * The column is a datetime because the section's order needs a tiebreaker
     * finer than a day, and an author has no reason to type a time.
     */
    public function testADateBecomesTheStartOfThatDay(): void
    {
        $parsed = Import::parse(self::file(
            "title: T\npublished: 2026-12-31\nauthor: A\nsummary: S",
            'Prose.',
        ));

        self::assertSame('2026-12-31 00:00:00', $parsed['published_at']);
    }

    public function testAMonthThatDoesNotExistIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('names a day that does not exist');

        Import::parse(self::file("title: T\npublished: 2026-13-45\nauthor: A\nsummary: S", 'Prose.'));
    }

    /**
     * The case that is the reason this is hand-rolled rather than `strtotime`.
     *
     * Measured: `strtotime('2026-02-30')` is not an error, it is **2 March**.
     * A finger slip in a date would become a real, plausible, wrong date and
     * nothing would ever mention it. `checkdate` is the only thing here that
     * asks whether the day exists.
     */
    public function testTheThirtiethOfFebruaryIsRefusedRatherThanRolledForward(): void
    {
        self::assertSame(
            '2026-03-02',
            date('Y-m-d', (int) strtotime('2026-02-30')),
            'if this ever fails, strtotime got stricter and this guard can go',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('names a day that does not exist');

        Import::parse(self::file("title: T\npublished: 2026-02-30\nauthor: A\nsummary: S", 'Prose.'));
    }

    /**
     * And prose is refused before it can become a date at all.
     *
     * `strtotime` reads `March 2026` as the first of March and `next tuesday`
     * as whichever day that happens to be when the import runs -- a date that
     * changes between two installs.
     */
    public function testProseInTheDateFieldIsRefused(): void
    {
        self::assertNotFalse(strtotime('next tuesday'), 'strtotime takes relative expressions');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('published must be written YYYY-MM-DD');

        Import::parse(self::file("title: T\npublished: next tuesday\nauthor: A\nsummary: S", 'Prose.'));
    }

    public function testAHeroAndItsAltTextAreRead(): void
    {
        $parsed = Import::parse(self::file(
            self::HEADER . "\nhero: window-seat.jpg\nhero_alt: A view down a wing at altitude",
            'Prose.',
        ));

        self::assertSame('window-seat.jpg', $parsed['hero']);
        self::assertSame('A view down a wing at altitude', $parsed['hero_alt']);
    }

    /**
     * A hero with nothing said about it would ship in silence.
     *
     * Nothing renders differently, nothing fails, and a reader using a screen
     * reader gets an unlabelled image. So the file is what refuses.
     */
    public function testAHeroWithoutAltTextIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must say what it shows in `hero_alt`');

        Import::parse(self::file(self::HEADER . "\nhero: window-seat.jpg", 'Prose.'));
    }

    /** And alt text with no image is a leftover from deleting the image. */
    public function testAltTextWithoutAHeroIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('describes nothing');

        Import::parse(self::file(self::HEADER . "\nhero_alt: A view down a wing", 'Prose.'));
    }

    /**
     * The lengths are checked here so the message names the file.
     *
     * With STRICT_TRANS_TABLES the column would refuse these too, but as a SQL
     * error naming a column, from a command that has already written the posts
     * ahead of this one.
     */
    #[DataProvider('overlongFields')]
    public function testAFieldTooLongForItsColumnIsRefused(string $header, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expected);

        Import::parse(self::file($header, 'Prose.'));
    }

    /** @return array<string, array{string, string}> */
    public static function overlongFields(): array
    {
        return [
            'title' => [
                'title: ' . str_repeat('a', 121) . "\npublished: 2026-03-01\nauthor: A\nsummary: S",
                'title is 121 characters and the column holds 120',
            ],
            'summary' => [
                "title: T\npublished: 2026-03-01\nauthor: A\nsummary: " . str_repeat('a', 256),
                'summary is 256 characters and the column holds 255',
            ],
            'hero_alt' => [
                "title: T\npublished: 2026-03-01\nauthor: A\nsummary: S\n"
                    . 'hero: a.jpg' . "\nhero_alt: " . str_repeat('a', 161),
                'hero_alt is 161 characters and the column holds 160',
            ],
        ];
    }

    #[DataProvider('brokenFiles')]
    public function testABrokenFileIsRefused(string $file, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expected);

        Import::parse($file);
    }

    /** @return array<string, array{string, string}> */
    public static function brokenFiles(): array
    {
        return [
            'no fence' => ["title: T\n", 'must open with a --- fence'],
            'unclosed' => ["---\ntitle: T\n", 'never closed'],
            'no prose' => [self::file(self::HEADER, ''), 'no prose after the header'],
            'unknown key' => [
                self::file(self::HEADER . "\npublised: 2026-01-01", 'Prose.'),
                'unknown header key `publised`',
            ],
            'missing author' => [
                self::file("title: T\npublished: 2026-03-01\nsummary: S", 'Prose.'),
                'header is missing `author`',
            ],
            'empty value' => [
                self::file("title: T\npublished: 2026-03-01\nauthor:\nsummary: S", 'Prose.'),
                'header key `author` has no value',
            ],
            'twice' => [
                self::file(self::HEADER . "\nauthor: Someone Else", 'Prose.'),
                'header key `author` appears twice',
            ],
            'not a date' => [
                self::file("title: T\npublished: March 2026\nauthor: A\nsummary: S", 'Prose.'),
                'published must be written YYYY-MM-DD',
            ],
        ];
    }

    /**
     * A body cannot open its own `h1`.
     *
     * The page has one already -- the title, in the band -- so a second is two
     * documents in one heading list and nothing on screen looks wrong. The
     * `NormalizeHeadings` extension was going to handle this until it was
     * measured: it leaves a body `#` as an `h1`, so it would have looked like
     * a guard and been none.
     */
    public function testABodyThatOpensItsOwnTopLevelHeadingIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('start the prose at `##`');

        Import::parse(self::file(self::HEADER, "# A second h1\n\nProse."));
    }

    public function testAHeadingLowerDownIsStillFine(): void
    {
        $parsed = Import::parse(self::file(self::HEADER, "## A heading\n\nProse.\n\n### Deeper"));

        self::assertStringContainsString('### Deeper', $parsed['body']);
    }

    /** A file saved on Windows is not a parse error. */
    public function testACarriageReturnFileIsRead(): void
    {
        $file = str_replace("\n", "\r\n", self::file(self::HEADER, 'Prose.'));

        self::assertSame('A Writer', Import::parse($file)['author']);
    }

    /** A horizontal rule in the prose is not a second fence. */
    public function testAHorizontalRuleInTheProseSurvives(): void
    {
        $body = "One paragraph.\n\n---\n\nAnother.";

        self::assertSame($body, Import::parse(self::file(self::HEADER, $body))['body']);
    }

    /*
    |--------------------------------------------------------------------------
    | The image check
    |--------------------------------------------------------------------------
    |
    | A hero that is not committed is a broken image on the card, in the
    | section, and in whatever A8.5 renders mid-body -- three broken things
    | from one typo, none of which fails anything.
    |
    */

    public function testAHeroThatIsNotCommittedIsNamed(): void
    {
        $missing = Import::missingImages([
            'picking-a-seat' => ['hero' => 'not-a-real-file.jpg'],
        ]);

        self::assertCount(1, $missing);
        self::assertStringContainsString('picking-a-seat.md', $missing[0]);
        self::assertStringContainsString('not-a-real-file.jpg', $missing[0]);
    }

    public function testAPostWithNoHeroIsNotAMissingImage(): void
    {
        self::assertSame([], Import::missingImages(['picking-a-seat' => ['hero' => null]]));
    }

    private static function file(string $header, string $body): string
    {
        return "---\n" . $header . "\n---\n\n" . $body . "\n";
    }
}
