<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * The release-label check must read the labels, not a snapshot of them.
 *
 * `gh pr create --label` opens a pull request before it attaches the label, so
 * the `opened` event's payload can carry an empty label list for a pull
 * request that is correctly labelled a second later. Reading
 * `github.event.pull_request.labels` there failed two of four consecutive pull
 * requests. The `labeled` run then passed, GitHub took the later result for
 * the check name, and the merge was never actually blocked — which is the
 * worst outcome available: a red check that means nothing, on the one check
 * whose job is to be believed.
 *
 * Guarded rather than left to the comment in the workflow, because reverting
 * it looks like a simplification and fails the way it failed before: not on
 * the pull request that made the change, and not every time.
 *
 * `release.yml` is exempt and says so. It fires on `closed`, whose snapshot is
 * taken at merge, by which point the labels have been settled for as long as
 * the pull request has been open.
 */
final class ReleaseLabelCheckTest extends TestCase
{
    public function testTheLabelCheckDoesNotReadTheEventPayload(): void
    {
        $workflow = (string) file_get_contents(Helper::getRootDir() . '/.github/workflows/pr-labels.yml');

        // Comments in there name the thing they are forbidding, so they go
        // first -- the same reason PromisesTest strips them.
        $yaml = (string) preg_replace('/^\s*#.*$/m', '', $workflow);

        self::assertStringNotContainsString(
            'github.event.pull_request.labels',
            $yaml,
            'the labels have to be read at run time, or the `opened` run races the label',
        );

        self::assertStringContainsString(
            'gh pr view',
            $yaml,
            'and read from the API instead',
        );
    }
}
