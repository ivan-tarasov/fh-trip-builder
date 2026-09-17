<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Helper;

/**
 * Every admin `<table>` has an accessible name.
 *
 * Found in the G5.3 (#318) audit: every table in the panel already had its
 * column headers right, but a sighted reader was the only one who could tell
 * what a table was *for* -- the heading sitting visibly above it was never
 * actually linked to it, so a screen reader's own table-navigation command
 * showed every one of them unlabelled. `aria-label`/`aria-labelledby` is the
 * fix; this is what stops the next new table shipping without either.
 *
 * Scoped to `templates/admin` -- this is what G5.3 audited, not the public
 * site.
 */
final class EveryAdminTableHasANameTest extends TestCase
{
    public function testEveryTableDeclaresItsName(): void
    {
        $offences = [];
        $checked = 0;

        foreach (self::templates() as $file => $contents) {
            foreach (self::tableTags($contents) as $line => $tag) {
                $checked++;

                if (!str_contains($tag, 'aria-label=') && !str_contains($tag, 'aria-labelledby=')) {
                    $offences[] = $file . ':' . $line;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no tables found at all; this guard is looking in the wrong place');
        self::assertSame(
            [],
            $offences,
            'A <table> with neither aria-label nor aria-labelledby has no accessible name -- a sighted '
            . 'reader sees a heading above it; nothing tells a screen reader the two belong together.',
        );
    }

    /**
     * Every `<table ...>` opening tag in a template, keyed by the line it
     * opens on -- the same reasoning {@see EveryImageKnowsItsSizeTest} gives
     * for blanking Twig comments first rather than stripping them.
     *
     * @return array<int, string>
     */
    private static function tableTags(string $contents): array
    {
        $withoutComments = (string) preg_replace_callback(
            '/\{#.*?#\}/s',
            static fn(array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            $contents,
        );

        preg_match_all('/<table\b[^>]*>/s', $withoutComments, $found, PREG_OFFSET_CAPTURE);

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
        $root = Helper::getRootDir() . '/templates/admin';

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
