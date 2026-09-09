<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Copy that promises something the app cannot do.
 *
 * This exists because of one sentence. `checkout/confirmation.html.twig` said
 * "We sent the ticket to you@example.com. It can take a few minutes to
 * arrive", and there is no mailer in this project -- composer requires dotenv,
 * console, twig and commonmark and nothing else. It was wrong for as long as
 * the page existed, and nothing failed, because a promise is invisible to a
 * test suite that only checks what renders.
 *
 * Then it spread. The /help/ticket-not-received article was written later, in
 * good faith, and repeated it: the ticket "goes to the address you gave at
 * checkout", look in your "spam folder" if it has not arrived. Advice for
 * waiting on something that was never coming, on the one page somebody reads
 * *because* it has not come.
 *
 * So the guard is on the phrases rather than on the two files. If a mailer is
 * ever added, delete this test in the commit that adds it -- and not before.
 */
final class PromisesTest extends TestCase
{
    /**
     * Said of mail this app has no way to send.
     *
     * Deliberately narrow. "Email" on its own is fine -- checkout asks for an
     * address and the booking page shows it -- and the subscribe form saying
     * what an address is *for* is a different question from a page saying a
     * message has already gone.
     */
    private const array UNKEEPABLE = [
        'we sent',
        'we have sent',
        'we will send',
        'we emailed',
        'check your inbox',
        'confirmation email',
    ];

    /**
     * "spam folder" is not on that list, and the first draft had it there.
     *
     * It caught the replacement copy -- "there is no spam folder worth
     * checking" -- which is the sentence a reader most needs, because looking
     * there is the next thing they would do. A phrase that appears in both the
     * promise and its denial cannot tell them apart, and the fix is to guard
     * the phrases that can only ever be a promise rather than to write around
     * the test.
     */

    public function testNoTemplatePromisesMailNothingCanSend(): void
    {
        $offences = [];

        foreach (self::templates() as $path) {
            $prose = self::withoutComments((string) file_get_contents($path));

            foreach (self::UNKEEPABLE as $phrase) {
                if (stripos($prose, $phrase) !== false) {
                    $offences[] = basename($path) . ' says "' . $phrase . '"';
                }
            }
        }

        self::assertSame([], $offences, 'templates promising mail: ' . implode('; ', $offences));
    }

    /**
     * The comments are where the explanation lives, so they are the one place
     * the phrases are allowed -- the fix for this bug is written out in both
     * files it touched, quoting the sentence it removed.
     */
    private static function withoutComments(string $twig): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $twig);
    }

    /** @return list<string> */
    private static function templates(): array
    {
        $root = __DIR__ . '/../../../frontend/template';
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
