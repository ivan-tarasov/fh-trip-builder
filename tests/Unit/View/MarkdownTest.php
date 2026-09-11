<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\View\Markdown;

/**
 * The one converter three families of prose go through.
 *
 * `/about` renders the README, help renders an article body and Airside
 * renders a post, all from this method -- so an extension added for one of
 * them lands on the other two. That is why every extension here is either
 * opt-in syntax or a safety header, and why the guarantees at the top of this
 * file are asserted rather than trusted: `html_input => 'escape'` is what
 * makes it safe to print operator-written text at all, and an extension that
 * quietly handed back a way to write markup would undo it.
 */
final class MarkdownTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | What must stay true
    |--------------------------------------------------------------------------
    */

    public function testRawHtmlIsStillEscapedRatherThanPassedThrough(): void
    {
        $html = Markdown::toHtml('<div onclick="alert(1)">text</div>');

        self::assertStringNotContainsString('<div', $html);
        self::assertStringContainsString('&lt;div', $html);
    }

    public function testAnUnsafeLinkSchemeIsStillDropped(): void
    {
        self::assertStringNotContainsString('javascript:', Markdown::toHtml('[x](javascript:alert(1))'));
    }

    /*
    |--------------------------------------------------------------------------
    | Callouts, and the allow-list that makes them safe
    |--------------------------------------------------------------------------
    |
    | A body cannot contain a `<div>`, so a callout has to be markdown. The
    | attribute goes on its own line above the block, which puts the class on
    | the blockquote itself rather than on the paragraph inside it.
    |
    */

    public function testACalloutIsABlockquoteWithAClass(): void
    {
        $html = Markdown::toHtml("{.callout}\n> **Key point.** The rest of it.");

        self::assertStringContainsString('<blockquote class="callout">', $html);
        self::assertStringContainsString('<strong>Key point.</strong>', $html);
    }

    /**
     * `style` is the reason the allow-list is not empty.
     *
     * With no allow-list the extension strips `onclick` on its own but lets
     * `style` through, and one line of markdown could then cover the page with
     * a fixed-position element. Measured, which is how the allow-list came to
     * exist.
     */
    public function testAStyleAttributeIsRefused(): void
    {
        $html = Markdown::toHtml('Text {style="position:fixed;top:0"}');

        self::assertStringNotContainsString('style', $html);
        self::assertStringContainsString('Text', $html);
    }

    /**
     * And `id` is left out, so a body cannot take one the page depends on.
     *
     * `{#main}` would otherwise give the body the id the skip link lands on,
     * and the link would start sending readers into the middle of an article.
     */
    public function testAnIdAttributeIsRefused(): void
    {
        self::assertStringNotContainsString('id=', Markdown::toHtml('Text {#main}'));
    }

    public function testAnEventHandlerIsRefused(): void
    {
        self::assertStringNotContainsString('onclick', Markdown::toHtml('Text {onclick="alert(1)"}'));
    }

    /*
    |--------------------------------------------------------------------------
    | The rest, all opt-in
    |--------------------------------------------------------------------------
    */

    public function testAnOutboundLinkCarriesRelAndAnInternalOneDoesNot(): void
    {
        $html = Markdown::toHtml('[out](https://example.com) [in](/help/baggage) [anchor](#top)');

        self::assertStringContainsString('rel="noopener noreferrer" href="https://example.com"', $html);
        self::assertStringNotContainsString('rel="noopener noreferrer" href="/help/baggage"', $html);
        self::assertStringNotContainsString('rel="noopener noreferrer" href="#top"', $html);
    }

    /** No new window: that is a decision per link, not for the whole site. */
    public function testAnOutboundLinkDoesNotOpenANewWindow(): void
    {
        self::assertStringNotContainsString('target=', Markdown::toHtml('[out](https://example.com)'));
    }

    public function testAFootnoteBecomesAReferenceAndANote(): void
    {
        $html = Markdown::toHtml("Rules change[^1].\n\n[^1]: As of 2026.");

        self::assertStringContainsString('class="footnote-ref"', $html);
        self::assertStringContainsString('As of 2026.', $html);
    }

    public function testADescriptionListRendersAsOne(): void
    {
        $html = Markdown::toHtml("Passport\n: The one you booked with.");

        self::assertStringContainsString('<dl>', $html);
        self::assertStringContainsString('<dt>', $html);
        self::assertStringContainsString('<dd>', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | A contents list, on posts only
    |--------------------------------------------------------------------------
    |
    | Measured on a 4,900-word post: twelve screens of scrolling, 24 headings,
    | no ids, and no way to see the shape of it or jump within it. A8.1 left
    | these out because they were a change to the shared converter and would
    | have put a contents list over three headings on every help article and
    | the README. `toPostHtml()` is what makes them affordable for posts alone.
    |
    */

    public function testAPostAsksForItsContentsListByWritingOne(): void
    {
        $html = Markdown::toPostHtml("[TOC]\n\n## First\n\nText.\n\n### Under it\n\nText.");

        self::assertStringContainsString('class="table-of-contents"', $html);
        self::assertStringContainsString('href="#content-first"', $html);
        // And the link has somewhere to land.
        self::assertStringContainsString('id="content-first"', $html);
    }

    /** A post that does not ask gets none, however many headings it has. */
    public function testAPostWithoutThePlaceholderGetsNoContentsList(): void
    {
        $html = Markdown::toPostHtml("## One\n\nText.\n\n## Two\n\nText.\n\n## Three\n\nText.");

        self::assertStringNotContainsString('table-of-contents', $html);
    }

    /**
     * And help and `/about` never get one, which is the deferral A8.1 made.
     *
     * The regression this guards: moving the extensions onto the shared
     * converter would put a contents list on five help articles and the
     * README, none of which asked for one.
     */
    public function testTheSharedConverterHasNoContentsListAtAll(): void
    {
        $html = Markdown::toHtml("[TOC]\n\n## First\n\nText.\n\n## Second\n\nText.");

        self::assertStringNotContainsString('table-of-contents', $html);
        self::assertStringNotContainsString('id="content-first"', $html);
        self::assertStringContainsString('[TOC]', $html, 'the placeholder stays as text where nothing consumes it');
    }

    /**
     * Nothing already written renders differently.
     *
     * The reason the four extensions could go on the shared converter rather
     * than an Airside-only one: there is no `{...}` in the README or in any
     * help body, so the only syntax that changed meaning is syntax nobody has
     * used. Held here so that stays a fact.
     */
    public function testOrdinaryProseIsUntouched(): void
    {
        $markdown = "## A heading\n\nA sentence with `code --flag` and a [link](/help/baggage).";

        $html = Markdown::toHtml($markdown);

        self::assertStringContainsString('<h2>A heading</h2>', $html);
        self::assertStringContainsString('<code>code --flag</code>', $html);
        self::assertStringContainsString('<a href="/help/baggage">link</a>', $html);
    }
}
