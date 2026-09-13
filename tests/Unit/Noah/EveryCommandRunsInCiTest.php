<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Helper;

/**
 * Every `noah` command is named somewhere in the CI workflow.
 *
 * Three of fifteen were ever run there. The rest could stop working for a whole
 * release and nothing would say so until somebody typed one on a server —
 * which is how seven migrations came to exist without a build having applied
 * one, and how `0007` came to rewrite 547,000 production rows having failed
 * twice locally for reasons no build could have caught (E10.1, #148).
 *
 * This asserts the *naming*, not the running. A command could be listed in a
 * step that does nothing useful with it. What it catches is the case that
 * actually happens: a command added, and the workflow never touched.
 */
final class EveryCommandRunsInCiTest extends TestCase
{
    public function testNoCommandIsMissingFromTheWorkflow(): void
    {
        $workflow = (string) file_get_contents(Helper::getRootDir() . '/.github/workflows/ci.yml');

        $missing = [];

        foreach (self::commands() as $name => $file) {
            if (!str_contains($workflow, 'php noah ' . $name)) {
                $missing[] = $name . ' (' . $file . ')';
            }
        }

        sort($missing);

        self::assertSame(
            [],
            $missing,
            'A noah command is never run by CI. Add it to the sweep in ci.yml — read-only where a '
            . 'flag offers one, and assert the refusal where a real run is not safe.',
        );
    }

    public function testTheWorkflowAppliesMigrationsForReal(): void
    {
        $workflow = (string) file_get_contents(Helper::getRootDir() . '/.github/workflows/ci.yml');

        self::assertMatchesRegularExpression(
            '/php noah db:migrate(?! --dry-run)/',
            $workflow,
            'CI must apply migrations, not only check them. A --dry-run proves nothing about the SQL.',
        );
    }

    /**
     * Command name => the file that declares it.
     *
     * @return array<string, string>
     */
    private static function commands(): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Helper::getRootDir() . '/src/Noah'));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match("/name: '([a-z]+:[a-z-]+)'/", $contents, $name) === 1) {
                $found[$name[1]] = substr($file->getPathname(), strlen(Helper::getRootDir()) + 1);
            }
        }

        self::assertNotEmpty($found, 'No commands were found to check.');

        return $found;
    }
}
