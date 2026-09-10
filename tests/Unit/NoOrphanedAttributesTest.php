<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * An attribute left above the wrong declaration.
 *
 * The sibling of `NoStackedDocblocksTest`, from the same stretch of work: a
 * `#[DataProvider]` whose method had been cut bound to the next method instead
 * and ran it five times against data meant for another test. Everything
 * passed.
 *
 * "Above nothing" is not the danger, because PHP will not parse it -- an
 * attribute over a non-declaration is a syntax error and cannot reach a
 * commit. Above the *wrong* thing is the danger, and it is silent.
 *
 * PHPUnit does not help. Measured on 11.5.56: a data provider on a method that
 * declares no parameters runs the test once per row and reports every one of
 * them as a pass, because PHP lets a caller pass arguments a function never
 * declared.
 */
final class NoOrphanedAttributesTest extends TestCase
{
    private const string PROVIDER_WITHOUT_PARAMETERS = 'names a data provider, but the method takes no parameters';
    private const string SEPARATED_FROM_ITS_NEIGHBOUR = 'is separated from the group below it, which is what debris looks like';

    public function testNoFileCarriesAnOrphanedAttribute(): void
    {
        $offences = [];

        foreach (self::sources() as $path) {
            foreach (self::orphaned((string) file_get_contents($path)) as [$line, $reason]) {
                $offences[] = sprintf(
                    '%s:%d %s',
                    substr($path, strlen(self::root()) + 1),
                    $line,
                    $reason,
                );
            }
        }

        self::assertSame([], $offences, 'delete the stranded attribute, or move it onto what it describes');
    }

    /*
    |--------------------------------------------------------------------------
    | The check, checked
    |--------------------------------------------------------------------------
    |
    | Neither rule fires anywhere in the tree today, so a green sweep proves
    | nothing on its own. The samples below are what stands behind it. The
    | third and fourth are the two ways a plausible rule gets this wrong.
    |
    */

    public function testTheCheckCatchesAProviderOnAMethodTakingNoParameters(): void
    {
        $code = <<<'SAMPLE'
            <?php
            #[DataProvider('rows')]
            public function testThing(): void {}
            SAMPLE;

        self::assertSame([[2, self::PROVIDER_WITHOUT_PARAMETERS]], self::orphaned($code));
    }

    public function testAProviderOnAMethodThatTakesTheDataIsFine(): void
    {
        $code = <<<'SAMPLE'
            <?php
            #[DataProvider('rows')]
            public function testThing(int $row): void {}
            SAMPLE;

        self::assertSame([], self::orphaned($code));
    }

    /**
     * Stacking two groups is legal PHP, and stays legal here.
     *
     * `#[Test]` above `#[DataProvider]` is ordinary, so counting groups is not
     * the rule. What separates debris from a stack is the gap: a leftover sits
     * where its own method used to be, and methods are a blank line apart.
     */
    public function testTwoAdjacentGroupsAreNotAnOffence(): void
    {
        $code = <<<'SAMPLE'
            <?php
            #[Test]
            #[DataProvider('rows')]
            public function thing(int $row): void {}
            SAMPLE;

        self::assertSame([], self::orphaned($code));
    }

    /**
     * And a blank line before the declaration is this codebase's own layout.
     *
     * Every `#[AsCommand]` in `src/Noah` is written with a blank line, and
     * sometimes a docblock, between the attribute and its class. Six files.
     * The funnel asked for a rule that would have flagged all six -- the same
     * way E6.1's first version flagged four `/* |---| *\/` banners -- so the
     * separation only counts between two groups, never before a declaration.
     */
    public function testABlankLineAndADocblockBeforeAClassIsNotAnOffence(): void
    {
        $code = <<<'SAMPLE'
            <?php
            #[AsCommand(
                name: 'flights:add',
            )]

            /**
             * The command.
             */
            class Generate {}
            SAMPLE;

        self::assertSame([], self::orphaned($code));
    }

    public function testAGroupSeparatedFromTheOneBelowItIsAnOffence(): void
    {
        $code = <<<'SAMPLE'
            <?php
            #[DataProvider('leftBehind')]

            #[DataProvider('theRealOne')]
            public function testThing(int $row): void {}
            SAMPLE;

        self::assertSame([[2, self::SEPARATED_FROM_ITS_NEIGHBOUR]], self::orphaned($code));
    }

    /**
     * Nothing inside a string counts, which a line scan cannot tell.
     */
    public function testAnAttributeInsideAStringIsNotOne(): void
    {
        $code = <<<'SAMPLE'
            <?php
            $sample = "#[DataProvider('rows')]";
            $pattern = '#\#\[DataProvider#';
            SAMPLE;

        self::assertSame([], self::orphaned($code));
    }

    /*
    |--------------------------------------------------------------------------
    | The detector
    |--------------------------------------------------------------------------
    */

    /**
     * Every stranded attribute in one file's source, as [line, reason].
     *
     * @return list<array{int, string}>
     */
    private static function orphaned(string $code): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_ATTRIBUTE) {
                continue;
            }

            $end = self::groupEnds($tokens, $i);

            // Whatever the group is written above, and whether a blank line or
            // a comment stands in between.
            $separated = false;
            $next = $end + 1;

            for (; $next < $count; $next++) {
                $id = is_array($tokens[$next]) ? $tokens[$next][0] : null;

                if ($id === T_WHITESPACE) {
                    $separated = $separated || substr_count($tokens[$next][1], "\n") > 1;

                    continue;
                }

                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    $separated = true;

                    continue;
                }

                break;
            }

            $stacked = $next < $count
                && is_array($tokens[$next])
                && $tokens[$next][0] === T_ATTRIBUTE;

            if ($stacked && $separated) {
                $found[] = [$tokens[$i][2], self::SEPARATED_FROM_ITS_NEIGHBOUR];
            }

            if (self::namesADataProvider($tokens, $i, $end) && self::takesNoParameters($tokens, $end)) {
                $found[] = [$tokens[$i][2], self::PROVIDER_WITHOUT_PARAMETERS];
            }
        }

        return $found;
    }

    /**
     * The index of the `]` closing the group that opens at `$start`.
     *
     * `T_ATTRIBUTE` is the `#[` itself, so it opens a bracket as much as any
     * `[` in the arguments does.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function groupEnds(array $tokens, int $start): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_ATTRIBUTE) {
                    $depth++;
                }

                continue;
            }

            if ($token === '[') {
                $depth++;
            } elseif ($token === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Whether the group names PHPUnit's data-provider attribute.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function namesADataProvider(array $tokens, int $start, int $end): bool
    {
        for ($i = $start; $i <= $end; $i++) {
            $token = $tokens[$i];

            if (is_array($token)
                && $token[0] === T_STRING
                && in_array($token[1], ['DataProvider', 'DataProviderExternal'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the declaration under the group is a method with an empty
     * parameter list.
     *
     * The walk ends at the first punctuation, and every declaration brings
     * some with it -- `{` for a class, `=` for a property, `(` for a method --
     * so an attribute on a class stops at the brace and never reaches the
     * first method inside it.
     *
     * There was a second guard here, listing the modifiers and rejecting
     * anything else. A mutant that deleted it changed nothing, and comparing
     * both walks over all 231 files and 75 attribute groups gave identical
     * answers: the punctuation always arrives first, so the list only ever
     * described what could no longer be reached.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function takesNoParameters(array $tokens, int $end): bool
    {
        $count = count($tokens);

        for ($i = $end + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                // A nested `[` or `]` belongs to a following attribute group's
                // arguments, not to the declaration.
                if ($token === '[' || $token === ']') {
                    continue;
                }

                return false;
            }

            if ($token[0] === T_FUNCTION) {
                return self::parameterListIsEmpty($tokens, $i);
            }
        }

        return false;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function parameterListIsEmpty(array $tokens, int $function): bool
    {
        $count = count($tokens);
        $open = $function;

        while ($open < $count && ($tokens[$open] ?? null) !== '(') {
            $open++;
        }

        for ($i = $open + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token === ')';
        }

        return false;
    }

    /**
     * Every PHP file worth checking.
     *
     * The same sweep `NoStackedDocblocksTest` uses, `noah` included because it
     * carries no extension.
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
