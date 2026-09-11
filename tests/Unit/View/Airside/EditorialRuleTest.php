<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View\Airside;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * The line between help and Airside, as far as it can be drawn by a test.
 *
 * The rule: help is what a reader needs in order to finish a booking *here*;
 * Airside is anything about travel itself. That is a judgement, and most of it
 * is not decidable -- no test can read a paragraph about seat maps and say
 * whether it belongs to a magazine.
 *
 * One half is decidable, and it is the half that goes wrong. A section blurs
 * by drift: a post about choosing a seat gains a line about choosing one *on
 * this site*, then a link into the search form, and now it is a help article
 * with a byline and a date. What follows checks for that, and claims nothing
 * about the rest.
 */
final class EditorialRuleTest extends TestCase
{
    /**
     * The app's own surfaces. A post that sends a reader to one of these is
     * not writing about travel any more.
     *
     * `/help` is deliberately absent: a post may reasonably point at a help
     * article, which is the two families doing their own jobs rather than
     * blurring. So are `/airlines` and `/airports`, which are reference pages
     * about the world and not steps in a booking.
     */
    private const array BOOKING_SURFACES = ['/search', '/checkout', '/my/'];

    public function testNoPostSendsAReaderIntoTheBookingFlow(): void
    {
        $offences = [];

        foreach (self::posts() as $path => $body) {
            foreach (self::BOOKING_SURFACES as $surface) {
                if (str_contains($body, '](' . $surface)) {
                    $offences[] = sprintf('%s links to %s', $path, $surface);
                }
            }
        }

        self::assertSame([], $offences, 'a post that routes a reader through checkout is a help article with a byline');
    }

    /**
     * And no subject is filed in both places at once.
     *
     * The most concrete way the rule fails: the same slug under both
     * directories means somebody answered "is this help or is this travel?"
     * twice and differently, and a reader would meet the subject twice with
     * two different answers.
     */
    public function testNoSubjectIsFiledInBothSections(): void
    {
        $both = array_intersect(self::slugs('help'), self::slugs('airside'));

        self::assertSame([], array_values($both));
    }

    /**
     * There has to be something to check, or the two above pass on an empty
     * directory and say nothing.
     */
    public function testTheSectionHasPostsToCheck(): void
    {
        self::assertNotEmpty(self::posts());
    }

    /** @return array<string, string> */
    private static function posts(): array
    {
        $found = [];

        foreach (glob(Helper::getRootDir() . '/config/content/airside/*.md') ?: [] as $path) {
            $found[basename($path)] = (string) file_get_contents($path);
        }

        return $found;
    }

    /** @return list<string> */
    private static function slugs(string $family): array
    {
        return array_map(
            static fn(string $path): string => basename($path, '.md'),
            glob(Helper::getRootDir() . '/config/content/' . $family . '/*.md') ?: [],
        );
    }
}
