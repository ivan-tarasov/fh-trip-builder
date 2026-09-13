<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Helper;

/**
 * Every `<img>` says how big it is.
 *
 * Without `width` and `height` the browser reserves no box, so the text under
 * the picture moves when it lands — and on a results card that is the row a
 * visitor is reading. The attributes need not match the file: the browser uses
 * them as a ratio and the CSS still decides the size, which is why a card hero
 * cropped to 16:9 by its stylesheet says `1600x900` rather than whatever its
 * photograph happens to be.
 *
 * **Tags, not lines.** E12 (#152) said there were fourteen of these; there were
 * five. The count came from reading `<img` line by line, and most of these tags
 * are spread over four or five lines with the attributes below the opening — so
 * eleven sized images read as unsized, and one sentence in a Twig comment read
 * as an image. A guard that counted the same way would fail on the day somebody
 * wrapped a line.
 */
final class EveryImageKnowsItsSizeTest extends TestCase
{
    public function testEveryImageDeclaresItsBox(): void
    {
        $offences = [];
        $checked = 0;

        foreach (self::templates() as $file => $contents) {
            foreach (self::imageTags($contents) as $line => $tag) {
                $checked++;

                if (!str_contains($tag, 'width=') || !str_contains($tag, 'height=')) {
                    $offences[] = $file . ':' . $line;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no images found at all; this guard is looking in the wrong place');
        self::assertSame(
            [],
            $offences,
            'An <img> without width and height reserves no space, so everything below it moves when '
            . 'the picture lands. The numbers are a ratio — the CSS still decides the size.',
        );
    }

    /**
     * Every `<img ...>` in a template, keyed by the line it opens on.
     *
     * Twig comments are blanked rather than removed, so prose about an `<img>`
     * is not read as one and the line numbers still point at the file.
     *
     * @return array<int, string>
     */
    private static function imageTags(string $contents): array
    {
        $withoutComments = (string) preg_replace_callback(
            '/\{#.*?#\}/s',
            static fn(array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            $contents,
        );

        preg_match_all('/<img\b[^>]*>/s', $withoutComments, $found, PREG_OFFSET_CAPTURE);

        $tags = [];

        foreach ($found[0] as [$tag, $offset]) {
            $tags[substr_count($withoutComments, "\n", 0, $offset) + 1] = $tag;
        }

        return $tags;
    }

    /**
     * @return array<string, string>
     */
    private static function templates(): array
    {
        $found = [];
        $root = Helper::getRootDir() . '/templates';

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $found[substr($file->getPathname(), strlen(Helper::getRootDir()) + 1)]
                    = (string) file_get_contents($file->getPathname());
            }
        }

        return $found;
    }
}
