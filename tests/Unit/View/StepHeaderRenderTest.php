<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\SearchUrl;
use TripBuilder\View\TwigRenderer;

/**
 * The line under the results heading, which says what was searched for.
 *
 * It named one day. That was the whole truth while a search was one day, and
 * stopped being it the moment a search could cover three: the cards below carry
 * their own dates, so a page of flights dated the 17th sat under a line that
 * said the 15th. The window has to reach the page, and a search that still
 * covers a single day has to read exactly as it did before.
 */
final class StepHeaderRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    private function render(string $path, int $step = 1): string
    {
        $search = SearchUrl::parse($path);

        self::assertNotNull($search, $path . ' should parse');

        $html = new TwigRenderer()->render('search/cards/step-header.html.twig', [
            'step' => $step,
            'step_title' => 'Choose your departing flight',
            'step_route' => 'Montreal (YUL) &rarr; London (LHR)',
            'step_date' => $step === 2 ? $search->return : $search->depart,
            'step_date_until' => $step === 2 ? $search->returnUntil() : $search->departUntil(),
            'total_flights_text' => '55 options',
            'depart_date_label' => $search->depart,
            'return_date_label' => $search->return,
        ]);

        // Decoded, so the assertions can be written the way the line reads. The
        // template spells its dash `&ndash;`, and a test matching that spelling
        // rather than the character would pass on a line saying nothing at all.
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return (string) preg_replace('/\s+/', ' ', trim($text));
    }

    public function testASingleDaySearchStillNamesOneDay(): void
    {
        self::assertStringContainsString('Thu, 15 Oct 2026', $this->render('/search/YUL151026LHRY1'));
        self::assertStringNotContainsString('–', $this->render('/search/YUL151026LHRY1'));
    }

    public function testAFlexibleSearchNamesTheWholeWindow(): void
    {
        $line = $this->render('/search/YUL151026x3LHRY1');

        // The month is said once, and the year only at the end -- the same
        // shape the assembled-trip line beside it already used.
        self::assertStringContainsString('Thu, 15 Oct – Sat, 17 Oct 2026', $line);
    }

    public function testTheReturnLegNamesItsOwnWindowRatherThanTheOutbound(): void
    {
        $line = $this->render('/search/YUL151026x3LHR221026x2Y1', step: 2);

        self::assertStringContainsString('Thu, 22 Oct – Fri, 23 Oct 2026', $line);
        self::assertStringNotContainsString('15 Oct', $line);
    }

    public function testAWindowOfOneIsNotDrawnAsARange(): void
    {
        // departUntil() answers the departure itself when the span is 1, and a
        // range from a day to that same day would read as a mistake.
        $line = $this->render('/search/YUL151026LHR221026x3Y1', step: 2);

        self::assertStringContainsString('Thu, 22 Oct – Sat, 24 Oct 2026', $line);
    }
}
