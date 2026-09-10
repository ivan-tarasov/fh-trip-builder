<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TripBuilder\Noah\Articles\Import;
use TripBuilder\Repository\ArticleCategoryRepository;

/**
 * Reading an article file, and refusing a broken one.
 *
 * `parse()` is static and takes a string for the reason `Rates::parse()` is:
 * every judgement the import makes is here, and none of it needs the disk or
 * the table. What is left in the command is a glob and a write.
 *
 * The header looks like YAML and is not -- five scalar keys, read by hand,
 * because a YAML parser would be a fourth dependency and the checkout
 * confirmation page tells a reader in prose that there are three. So the ways
 * a hand-rolled reader can be wrong are what this file is about.
 *
 * Every refusal below is a way a broken file becomes a broken page instead of
 * an error: no summary is a page with no meta description and no lead, and a
 * mistyped key is the one that would otherwise pass silently and sort the
 * articles into the wrong order.
 */
final class ArticlesImportTest extends TestCase
{
    /** A real article header, in the shape the committed files carry. */
    private const string HEADER = "title: Baggage\n"
        . "short: Bags\n"
        . "category: before-you-book\n"
        . "icon: fa-suitcase-rolling\n"
        . "position: 10\n"
        . 'summary: What you can bring is set by the fare you pick.';

    /** And a category header, which carries neither `short` nor `summary`. */
    private const string CATEGORY = "title: Before you book\n"
        . "icon: fa-magnifying-glass\n"
        . "accent: blue\n"
        . 'position: 10';

    public function testAGoodFileIsRead(): void
    {
        $parsed = Import::parse(self::file(self::HEADER, "## Cabin bags\n\nOne bag, always."));

        self::assertSame('Baggage', $parsed['title']);
        self::assertSame('Bags', $parsed['short']);
        self::assertSame('before-you-book', $parsed['category']);
        self::assertSame('fa-suitcase-rolling', $parsed['icon']);
        self::assertSame(10, $parsed['position']);
        self::assertSame('What you can bring is set by the fare you pick.', $parsed['summary']);
        self::assertSame("## Cabin bags\n\nOne bag, always.", $parsed['body']);
    }

    /**
     * The body is the prose and nothing else.
     *
     * Asserted rather than assumed because the header is cut by offset
     * arithmetic on the fences. A body that still carried `title: Baggage`
     * would render that line as a paragraph above the article's first heading,
     * on every page, which is visible but easy to leave in.
     */
    public function testTheHeaderIsNotPartOfTheBody(): void
    {
        $body = Import::parse(self::file(self::HEADER))['body'];

        self::assertStringNotContainsString('title:', $body);
        self::assertStringNotContainsString('---', $body);
        self::assertSame(trim($body), $body, 'the body should arrive trimmed');
    }

    /**
     * `short` is the one key a file may leave out.
     *
     * Null and not an empty string: the footer treats null as "use the title",
     * and an empty label would render a column of blank links.
     */
    public function testShortIsOptionalAndItsAbsenceIsNull(): void
    {
        $header = str_replace("short: Bags\n", '', self::HEADER);

        self::assertNull(Import::parse(self::file($header))['short']);
    }

    /**
     * A file saved on Windows is not a parse error.
     *
     * The fence test is `str_starts_with($text, "---\n")`, which a CRLF file
     * fails on its first byte -- so the whole import would refuse five valid
     * articles because somebody opened one in Notepad.
     */
    public function testACarriageReturnFileIsRead(): void
    {
        $file = str_replace("\n", "\r\n", self::file(self::HEADER, 'Prose.'));
        $parsed = Import::parse($file);

        self::assertSame('Baggage', $parsed['title']);
        self::assertSame('Prose.', $parsed['body']);
    }

    /**
     * A value may contain a colon, because a title reads like this: it does.
     *
     * The line is split on its first colon rather than exploded on every one,
     * and the difference only shows up on a value that carries a second.
     */
    public function testOnlyTheFirstColonSeparatesKeyFromValue(): void
    {
        $header = str_replace(
            'summary: What you can bring is set by the fare you pick.',
            'summary: Bags: what the fare decides',
            self::HEADER,
        );

        self::assertSame('Bags: what the fare decides', Import::parse(self::file($header))['summary']);
    }

    /**
     * And the prose keeps its own rules.
     *
     * The closing fence is found as the first `\n---` past the opening one, so
     * a thematic break in the markdown is prose and not a second header. If
     * that ever inverted, the body would be silently truncated at the rule --
     * a page that renders, ends early, and fails nothing.
     */
    public function testAHorizontalRuleInTheProseSurvives(): void
    {
        $body = Import::parse(self::file(self::HEADER, "One.\n\n---\n\nTwo."))['body'];

        self::assertSame("One.\n\n---\n\nTwo.", $body);
    }

    /**
     * A mistyped key is named, not dropped.
     *
     * `postion: 20` is the case worth writing down: quietly ignored, the
     * article keeps whatever position it had and the hub's order is wrong for
     * a reason nothing reports.
     */
    public function testAnUnknownKeyIsRefusedAndNamed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/`postion`/');

        Import::parse(self::file(self::HEADER . "\npostion: 20"));
    }

    /**
     * Position has to be a number, and the bad value is quoted back.
     */
    public function testANonNumericPositionIsRefused(): void
    {
        $header = str_replace('position: 10', 'position: first', self::HEADER);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/`first`/');

        Import::parse(self::file($header));
    }

    #[DataProvider('brokenFiles')]
    public function testABrokenFileIsRefused(string $file, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        Import::parse($file);
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenFiles(): iterable
    {
        yield 'no header at all' => ["title: Baggage\n\nProse.\n", 'no header'];
        yield 'header never closed' => ["---\n" . self::HEADER . "\n", 'never closed'];
        yield 'no prose' => [self::file(self::HEADER, ''), 'no prose'];
        yield 'a line that is not a pair' => [self::file(self::HEADER . "\nfa-suitcase"), 'not `key: value`'];
        yield 'the same key twice' => [self::file(self::HEADER . "\ntitle: Bags"), 'appears twice'];
        yield 'a key with no value' => [self::file(str_replace('title: Baggage', 'title:', self::HEADER)), '`title` has no value'];
        yield 'no title' => [self::file(str_replace("title: Baggage\n", '', self::HEADER)), 'missing `title`'];
        yield 'no category' => [
            self::file(str_replace("category: before-you-book\n", '', self::HEADER)),
            'missing `category`',
        ];
        yield 'no summary' => [
            self::file(str_replace('summary: What you can bring is set by the fare you pick.', '', self::HEADER)),
            'missing `summary`',
        ];
        yield 'no icon' => [self::file(str_replace("icon: fa-suitcase-rolling\n", '', self::HEADER)), 'missing `icon`'];
        yield 'no position' => [self::file(str_replace("position: 10\n", '', self::HEADER)), 'missing `position`'];
    }

    /**
     * A category file is read the same way, and its prose is its sentence.
     *
     * The one shape difference worth a test: a category has no `summary` key,
     * because the body is the summary. A file that carried both would be two
     * places to write the same line.
     */
    public function testACategoryFileIsRead(): void
    {
        $parsed = Import::parseCategory(self::file(self::CATEGORY, 'What the fare decides, before you pay.'));

        self::assertSame('Before you book', $parsed['title']);
        self::assertSame('fa-magnifying-glass', $parsed['icon']);
        self::assertSame('blue', $parsed['accent']);
        self::assertSame(10, $parsed['position']);
        self::assertSame('What the fare decides, before you pay.', $parsed['summary']);
    }

    /**
     * `accent` is the optional one, and its absence is the repository's default.
     *
     * Taken from ArticleCategoryRepository rather than written out here, so a
     * change to the default cannot leave this test asserting the old one.
     */
    public function testAccentIsOptionalAndFallsBackToTheDefault(): void
    {
        $header = str_replace("accent: blue\n", '', self::CATEGORY);

        self::assertSame(
            ArticleCategoryRepository::DEFAULT_ACCENT,
            Import::parseCategory(self::file($header))['accent'],
        );
    }

    /**
     * A category's summary is refused by length rather than by the column.
     *
     * With STRICT_TRANS_TABLES a long paragraph is a SQL error naming a
     * column; this names the file and says what the file should be instead.
     * The article summary is a header value and cannot run away like this --
     * a category's is free prose, which is why only this one is measured.
     */
    public function testACategoryDescriptionLongerThanTheColumnIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/one sentence, not a page/');

        Import::parseCategory(self::file(self::CATEGORY, str_repeat('word ', 60)));
    }

    /**
     * And a category with no key an article needs is still refused by name.
     */
    public function testACategoryHeaderIsCheckedAgainstItsOwnKeys(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown header key `summary`/');

        Import::parseCategory(self::file(self::CATEGORY . "\nsummary: not here"));
    }

    /** One file, composed the way the committed ones are written. */
    private static function file(string $header, string $body = 'Prose.'): string
    {
        return "---\n" . $header . "\n---\n\n" . $body . "\n";
    }
}
