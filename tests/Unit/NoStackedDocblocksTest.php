<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A docblock left stranded above the one that replaced it.
 *
 * PHP binds the *last* docblock before a declaration, so an orphan above it is
 * simply never read. Nothing fails, static analysis is happy, and the
 * explanation somebody took the trouble to write sits three lines above the
 * one anybody sees. It is the quietest possible way for a comment to go wrong:
 * not stale, but invisible.
 *
 * This exists because it kept happening. Four in one stretch of work: two from
 * a cut script of mine in `HelpRenderTest` -- alongside a dangling
 * `#[DataProvider]` that bound to the next method and ran it against the wrong
 * data five times -- two more found by sweeping a branch by hand, and one in
 * `ItineraryPresenter::buildNotices()`, where the older block held the notice
 * list and the deduplication rule and the newer one held the severities, so
 * half the explanation was unreachable.
 *
 * Every one of those was found by looking. A file nobody happens to open keeps
 * its orphan indefinitely.
 */
final class NoStackedDocblocksTest extends TestCase
{
    public function testNoFileCarriesAnOrphanedDocblock(): void
    {
        $offences = [];

        foreach (self::sources() as $path) {
            foreach (self::stacked((string) file_get_contents($path)) as [$orphan, $keeper]) {
                $offences[] = sprintf(
                    '%s:%d is never read -- the block at :%d is the one that binds',
                    substr($path, strlen(self::root()) + 1),
                    $orphan,
                    $keeper,
                );
            }
        }

        self::assertSame([], $offences, 'delete the stranded block, or merge it into the one below it');
    }

    /*
    |--------------------------------------------------------------------------
    | The check, checked
    |--------------------------------------------------------------------------
    |
    | A sweep that finds nothing is indistinguishable from a sweep that cannot
    | find anything, so the detector is exercised against samples rather than
    | trusted. The second and third of these are the two things the first
    | version of this got wrong.
    |
    */

    public function testTheCheckCatchesAnOrphanedDocblock(): void
    {
        $code = <<<'SAMPLE'
            <?php
            /**
             * The block that was replaced.
             */
            /**
             * The block that replaced it.
             */
            function f(): void {}
            SAMPLE;

        self::assertSame([[2, 5]], self::stacked($code));
    }

    /**
     * A `/* |---| *\/` banner above a docblock is this codebase's own idiom.
     *
     * `site.php`, `noah` and every config file in the repository are written
     * this way, and the first version of this check -- a line scan for `*\/`
     * followed by `/**` -- flagged four of them in a single new test file. The
     * tokenizer draws the distinction for free: a banner is `T_COMMENT`, a
     * docblock is `T_DOC_COMMENT`, and only the second kind can be orphaned.
     */
    public function testASectionBannerAboveADocblockIsNotOne(): void
    {
        $code = <<<'SAMPLE'
            <?php
            /*
            |--------------------------------------------------------------------------
            | A section
            |--------------------------------------------------------------------------
            */

            /**
             * The only docblock here.
             */
            function f(): void {}
            SAMPLE;

        self::assertSame([], self::stacked($code));
    }

    /**
     * And nothing inside a string counts, which a line scan cannot tell.
     */
    public function testADocblockInsideAStringIsNotOne(): void
    {
        $code = <<<'SAMPLE'
            <?php
            $pattern = '#/\*\*.*?\*/#s';
            $sample = "/** one */ /** two */";
            SAMPLE;

        self::assertSame([], self::stacked($code));
    }

    /**
     * A `//` note between two of them does not rescue the first.
     *
     * Found by a surviving mutant: the detector used to reset on any comment,
     * which let this through. Nothing binds the first block here either.
     */
    public function testAPlainCommentBetweenTwoDocblocksIsStillAnOrphan(): void
    {
        $code = <<<'SAMPLE'
            <?php
            /**
             * Stranded all the same.
             */
            // a note somebody left in between
            /**
             * The one that binds.
             */
            function f(): void {}
            SAMPLE;

        self::assertSame([[2, 6]], self::stacked($code));
    }

    /**
     * Two apart, with a declaration between them, is just two docblocks.
     */
    public function testTwoDocblocksWithSomethingBetweenThemAreFine(): void
    {
        $code = <<<'SAMPLE'
            <?php
            /** One. */
            function f(): void {}

            /** Two. */
            function g(): void {}
            SAMPLE;

        self::assertSame([], self::stacked($code));
    }

    /*
    |--------------------------------------------------------------------------
    | The detector
    |--------------------------------------------------------------------------
    */

    /**
     * Every stranded docblock in one file's source, as [orphan line, keeper].
     *
     * Two `T_DOC_COMMENT` tokens with nothing but whitespace between them. The
     * first cannot be reached by anything -- there is no declaration for it to
     * document -- so it is debris whatever it says.
     *
     * @return list<array{int, int}>
     */
    private static function stacked(string $code): array
    {
        $found = [];
        $previous = null;

        foreach (token_get_all($code) as $token) {
            // Single-character tokens -- `{`, `;` -- come back as plain
            // strings, and any of them means a declaration has intervened.
            $id = is_array($token) ? $token[0] : null;

            // Whitespace and an ordinary comment cannot bind a docblock, so a
            // block separated from the next one by only these is still
            // stranded. Skipping T_COMMENT here rather than resetting on it is
            // deliberate: resetting made `/** */ // a note /** */` pass, and
            // the first block there documents nothing either.
            if ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            }

            if ($id === T_DOC_COMMENT) {
                if ($previous !== null) {
                    $found[] = [$previous, $token[2]];
                }

                $previous = $token[2];

                continue;
            }

            // One reset, for everything that can carry a docblock. There were
            // two, and the second was unreachable -- every real declaration
            // brings punctuation with it, so the string branch above had
            // already fired. A mutant that deleted it changed nothing, which
            // is how it was found.
            $previous = null;
        }

        return $found;
    }

    /**
     * Every PHP file worth checking.
     *
     * `noah` is named on its own because it has no extension, and it is the
     * file most likely to get this wrong: it is section banners almost all the
     * way down.
     *
     * @return list<string>
     */
    private static function sources(): array
    {
        $found = [self::root() . '/noah'];

        foreach (['src', 'tests', 'config'] as $directory) {
            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::root() . '/' . $directory),
            );

            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        sort($found);

        self::assertGreaterThan(100, count($found), 'sanity: the sweep should reach the whole tree');

        return $found;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
