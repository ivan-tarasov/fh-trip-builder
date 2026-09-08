<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\View\TwigRenderer;

/**
 * The shell every place page is built on, and the one block that hides its
 * heading.
 *
 * `title_hidden` exists for the airline page: its first block opens with a
 * masthead carrying the mark and the name, so a heading above it saying the
 * name again is the same words twice. The temptation is then to drop the
 * heading, and dropping it costs two things that leave no visible trace -- the
 * section's accessible name, which is `aria-labelledby` pointing at that
 * heading's id, and the rail's anchor, which points at the section. Both would
 * still "work" on screen.
 */
final class PlaceShellRenderTest extends TestCase
{
    protected function setUp(): void
    {
        new Config('common');
    }

    /** @param array<string, mixed> $extra */
    private function render(array $extra = []): string
    {
        return new TwigRenderer()->render('partials/place.html.twig', [
            'heading' => 'Flights on Air Canada',
            'rail_title' => 'Air Canada',
            'blocks' => [
                [
                    'id' => 'general',
                    'title' => 'General information about Air Canada',
                    'nav' => 'General information',
                    'icon' => 'fa-circle-info',
                    'tone' => 'green',
                    'template' => 'partials/facts-grid.html.twig',
                    'when' => true,
                ] + $extra,
            ],
            'data' => ['facts' => [['icon' => 'fa-plane', 'value' => 'AC', 'label' => 'Code']]],
        ]);
    }

    public function testABlockShowsItsHeadingByDefault(): void
    {
        $html = $this->render();

        self::assertStringContainsString('General information about Air Canada', $html);
        self::assertStringNotContainsString('visually-hidden">General information', $html);
        self::assertStringNotContainsString('place__block-title visually-hidden', $html);
    }

    /**
     * Hidden, and still there: the section is named by it and the rail points
     * at the section.
     */
    public function testAHiddenHeadingIsStillInTheDocument(): void
    {
        $html = $this->render(['title_hidden' => true]);

        self::assertStringContainsString('place__block-title visually-hidden', $html);
        self::assertStringContainsString('General information about Air Canada', $html);
        self::assertStringContainsString('id="general-title"', $html);
        self::assertStringContainsString('aria-labelledby="general-title"', $html);
        // And the rail still offers the way in.
        self::assertStringContainsString('href="#general"', $html);
    }
}
