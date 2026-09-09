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
        'we will write',
        'we will email',
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
        self::assertSame([], self::offences(
            self::templates(),
            static fn(string $path): string => self::withoutComments((string) file_get_contents($path)),
        ));
    }

    /**
     * And nor does anything the server says back.
     *
     * The first version of this test read templates only, and missed the worst
     * one in the app: the subscribe endpoint answered "Done. We will write when
     * a fare drops." A promise is a promise wherever the string lives, and the
     * strings a controller hands to a page are exactly as visible as the ones
     * in the markup.
     *
     * Only string literals are searched, via the tokenizer, so the phrases stay
     * usable in the comments that explain why they were removed.
     */
    public function testNoServerMessagePromisesMailEither(): void
    {
        self::assertSame([], self::offences(self::sources(), self::stringLiterals(...)));
    }

    /**
     * @param list<string> $paths
     * @param callable(string): string $read
     * @return list<string>
     */
    private static function offences(array $paths, callable $read): array
    {
        $found = [];

        foreach ($paths as $path) {
            $prose = $read($path);

            foreach (self::UNKEEPABLE as $phrase) {
                if (stripos($prose, $phrase) !== false) {
                    $found[] = basename($path) . ' says "' . $phrase . '"';
                }
            }
        }

        return $found;
    }

    /**
     * Every quoted string in a PHP file, and nothing else.
     *
     * token_get_all() rather than a regex over the source: a regex cannot tell
     * a sentence in a docblock from one in a message, and this test needs to
     * allow the first while catching the second.
     */
    private static function stringLiterals(string $path): string
    {
        $strings = [];

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $strings[] = $token[1];
            }
        }

        return implode(' ', $strings);
    }

    /** @return list<string> */
    private static function sources(): array
    {
        return self::filesUnder(__DIR__ . '/../../../src', '.php');
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
        return self::filesUnder(__DIR__ . '/../../../frontend/template', '.twig');
    }

    /** @return list<string> */
    private static function filesUnder(string $root, string $extension): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
