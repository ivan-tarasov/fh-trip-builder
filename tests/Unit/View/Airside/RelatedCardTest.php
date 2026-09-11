<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View\Airside;

use PHPUnit\Framework\TestCase;
use TripBuilder\View\Markdown;

/**
 * The card that stands in the middle of a post naming another one.
 *
 * Inserted into the parsed document rather than spliced into the rendered
 * HTML. "Before the second `<h2>`" as a regular expression breaks the first
 * time a heading contains a link or a `<code>` span, and these bodies are
 * operator-written, so it would.
 */
final class RelatedCardTest extends TestCase
{
    /** @return array{slug: string, title: string, summary: string, hero: ?string, hero_alt: ?string} */
    private static function card(): array
    {
        return [
            'slug' => 'meals-on-flights',
            'title' => 'What the meal service actually is',
            'summary' => 'A sentence.',
            'hero' => null,
            'hero_alt' => null,
        ];
    }

    private static function body(int $sections): string
    {
        $out = [];

        for ($i = 1; $i <= $sections; $i++) {
            $out[] = "## Section {$i}\n\nSome prose.";
        }

        return implode("\n\n", $out);
    }

    public function testTheCardLandsBeforeTheSecondSection(): void
    {
        $html = Markdown::toPostHtml(self::body(4), self::card());

        $card = strpos($html, 'airside-inline');
        self::assertIsInt($card);

        $headings = [];
        preg_match_all('/<h2[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE);

        foreach ($m[0] as [$_, $offset]) {
            $headings[] = $offset;
        }

        self::assertCount(4, $headings);
        self::assertGreaterThan($headings[0], $card, 'after the first section opens');
        self::assertLessThan($headings[1], $card, 'and before the second');
    }

    /**
     * A post with one section has no middle to interrupt.
     */
    public function testAPostWithOneSectionGetsNoCard(): void
    {
        self::assertStringNotContainsString('airside-inline', Markdown::toPostHtml(self::body(1), self::card()));
    }

    public function testNoCardIsAskedForAndNoneAppears(): void
    {
        self::assertStringNotContainsString('airside-inline', Markdown::toPostHtml(self::body(4)));
    }

    /**
     * A heading inside something else is part of that thing.
     *
     * Counting it would put the card inside a blockquote, as a sibling of the
     * heading it was measuring from.
     */
    public function testAHeadingInsideABlockquoteIsNotASection(): void
    {
        $markdown = "## One\n\nProse.\n\n> ## Quoted\n>\n> Prose.\n\n## Two\n\nProse.";

        $html = Markdown::toPostHtml($markdown, self::card());

        self::assertStringContainsString('airside-inline', $html);

        // The quoted heading does not count, so the second section is `## Two`
        // and the card goes before that -- which puts it after the blockquote
        // has closed, not inside it.
        $card = (int) strpos($html, 'airside-inline');
        $quoteEnds = (int) strpos($html, '</blockquote>');
        $second = (int) strpos($html, '>Two<');

        self::assertGreaterThan($quoteEnds, $card, 'outside the blockquote');
        self::assertLessThan($second, $card, 'and before the section that really is the second');
    }

    /**
     * The heading this is measured against is the real one.
     *
     * A heading carrying a link or a code span is exactly what a regular
     * expression over the HTML gets wrong, which is why this works on the
     * document instead.
     */
    public function testAHeadingWithMarkupInsideItStillCounts(): void
    {
        $markdown = "## First\n\nProse.\n\n## A `code` heading with a [link](/help/baggage)\n\nProse.";

        $html = Markdown::toPostHtml($markdown, self::card());
        $card = (int) strpos($html, 'airside-inline');
        $second = (int) strpos($html, 'A <code>code</code> heading');

        self::assertGreaterThan(0, $second);
        self::assertLessThan($second, $card);
    }

    /**
     * The card is an `<aside>` with no heading, so it stays out of the
     * contents list a long post builds with `[TOC]`.
     *
     * A heading inside it would appear between two sections of this post,
     * describing a different one.
     */
    public function testTheCardIsNotInTheContentsList(): void
    {
        $html = Markdown::toPostHtml("[TOC]\n\n" . self::body(3), self::card());

        $toc = substr($html, (int) strpos($html, 'table-of-contents'), 400);

        self::assertStringNotContainsString('meal service', $toc);
        self::assertStringContainsString('Section 1', $toc);
    }

    /** And help and `/about` cannot get one at all. */
    public function testTheSharedConverterNeverInsertsACard(): void
    {
        self::assertStringNotContainsString('airside-inline', Markdown::toHtml(self::body(4)));
    }
}
