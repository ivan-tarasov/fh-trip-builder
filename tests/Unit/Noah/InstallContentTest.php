<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * What `app:install --with-content` runs, and what it must never run.
 *
 * A fresh install used to leave 254 airports, 105 airlines, zero flights and no
 * articles — a site whose route pages were all 404 and whose footer was missing
 * two columns. The flag closes the mechanical half of that gap (E21, #182).
 *
 * Read from the source rather than executed: running it needs a database, and
 * what is worth protecting here is which commands are composed and in which
 * order, not that the installer works — which the integration suite already
 * covers.
 */
final class InstallContentTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(Helper::getRootDir() . '/src/Noah/Db/Install.php');
    }

    public function testTheFlagExists(): void
    {
        self::assertStringContainsString("'with-content'", self::source());
    }

    /**
     * Articles before posts.
     *
     * Both stand alone today, but the posts are the half that reaches for S3.
     * Failing on credentials after the cheap half has already succeeded leaves
     * a more useful database than failing before it.
     */
    public function testItComposesExactlyTheTwoImportersInOrder(): void
    {
        self::assertSame(['articles:import', 'airside:import'], self::composed());
    }

    /**
     * An installer must not delete, and must not make a judgement it cannot
     * make.
     *
     * `db:prune` exists to destroy data that has no other copy. `flights:add`
     * writes 200,000 rows, takes minutes, and the runbook warns against running
     * it on a populated table — which is exactly the thing an installer cannot
     * know.
     */
    public function testTheInstallerNeitherDeletesNorGeneratesFlights(): void
    {
        $composed = self::composed();

        self::assertNotContains('db:prune', $composed, 'the installer would delete data');
        self::assertNotContains('flights:add', $composed, 'the installer would write 200,000 rows');
    }

    /**
     * The commands the flag actually runs, read off the list it iterates.
     *
     * The list and not the whole file: the docblock above it names `db:prune`
     * and `flights:add` to say why they are absent, and a test that searched
     * the source would read that as their presence.
     *
     * @return list<string>
     */
    private static function composed(): array
    {
        preg_match('/foreach \(\[([^\]]+)\] as \$command\)/', self::source(), $found);

        self::assertNotEmpty($found, 'the installer no longer iterates a list of commands');

        preg_match_all("/'([a-z]+:[a-z-]+)'/", $found[1], $names);

        return $names[1];
    }

    /**
     * The README no longer states the reason that expired.
     *
     * Articles were seeded from a CSV once, and an install would revert an
     * edited one. A7 made the files the whole truth; the claim outlived it by
     * long enough to be quoted back as a reason not to do this.
     */
    public function testTheReadmeNoLongerSaysAnInstallWouldRevertAnArticle(): void
    {
        $readme = (string) file_get_contents(Helper::getRootDir() . '/README.md');

        self::assertStringNotContainsString('would be reverted on the next install', $readme);
        self::assertStringContainsString('app:install --with-content', $readme);
    }
}
