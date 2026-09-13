<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use TripBuilder\Database\Dump;

/**
 * What `mysqldump` is told, and what it is never told on the command line.
 *
 * The dump itself is a subprocess; the only part a test can say anything
 * useful about is the instruction. That is also the part with the security
 * decision in it.
 */
final class DumpTest extends TestCase
{
    private const string PASSWORD = 'hunter2-s3cret';

    /**
     * The password is not an argument, because arguments are public.
     *
     * `mysqldump -p<password>` is readable in `ps` by every account on a shared
     * host, and this application deploys to one.
     */
    public function testThePasswordIsNeverOnTheCommandLine(): void
    {
        $arguments = new Dump('mysqldump', 'trip-builder')->arguments('/tmp/defaults.cnf');

        foreach ($arguments as $argument) {
            self::assertStringNotContainsString(self::PASSWORD, $argument);
            self::assertStringNotContainsString('-p', $argument, 'a password-looking flag is present');
        }
    }

    /**
     * `--defaults-extra-file` has to come first: mysqldump refuses to start if
     * any other option precedes it.
     */
    public function testTheDefaultsFileIsTheFirstOption(): void
    {
        $arguments = new Dump('mysqldump', 'trip-builder')->arguments('/tmp/defaults.cnf');

        self::assertSame('mysqldump', $arguments[0]);
        self::assertSame('--defaults-extra-file=/tmp/defaults.cnf', $arguments[1]);
        self::assertSame('trip-builder', $arguments[array_key_last($arguments)]);
    }

    /**
     * MySQL 8 needs the `PROCESS` privilege to dump tablespace information and
     * a hosting account does not have it. Without this flag the dump fails on
     * the server it is needed on and works on the laptop it was written on.
     */
    public function testTablespacesAreNotDumped(): void
    {
        self::assertContains(
            '--no-tablespaces',
            new Dump('mysqldump', 'trip-builder')->arguments('/tmp/x.cnf'),
            'this dump will fail on a shared host and nowhere else',
        );
    }

    public function testTheSnapshotIsConsistentWithoutLocking(): void
    {
        $arguments = new Dump('mysqldump', 'trip-builder')->arguments('/tmp/x.cnf');

        self::assertContains('--single-transaction', $arguments);
        self::assertContains('--quick', $arguments);
    }

    /**
     * A MySQL 8 client reads a histogram out of
     * `information_schema.COLUMN_STATISTICS` before each table, and MariaDB has
     * no such table -- so the dump dies on the first one. CI's MariaDB job did
     * exactly that the first time `db:backup` ever ran there.
     */
    public function testTheHistogramQueryIsTurnedOffWhereTheClientHasOne(): void
    {
        $arguments = new Dump('mysqldump', 'trip-builder', true)->arguments('/tmp/x.cnf');

        self::assertContains('--column-statistics=0', $arguments);
        self::assertSame('trip-builder', $arguments[array_key_last($arguments)]);
    }

    /**
     * And is absent otherwise, because MariaDB's own client refuses an option
     * it has no use for -- turning one dead dump into another.
     */
    public function testTheOptionIsAbsentWhereTheClientDoesNotKnowIt(): void
    {
        self::assertNotContains(
            '--column-statistics=0',
            new Dump('mysqldump', 'trip-builder')->arguments('/tmp/x.cnf'),
        );
    }

    /**
     * The answer comes from the binary and not from a version guess: a host can
     * pair either client with either server, and the pairing is the problem.
     */
    public function testAMissingBinarySupportsNothing(): void
    {
        self::assertFalse(Dump::supportsColumnStatistics('mysqldump-that-is-not-installed'));
    }

    /**
     * A `#` in a password starts a comment in this file format, and everything
     * after it is silently dropped — which surfaces as "access denied" and
     * sends you looking at the wrong thing entirely.
     */
    public function testThePasswordIsQuotedInTheDefaultsFile(): void
    {
        $contents = Dump::defaults('127.0.0.1', '3306', 'root', 'pass#word');

        self::assertStringContainsString('password="pass#word"', $contents);
    }

    public function testTheCredentialsFileIsReadableOnlyByItsOwner(): void
    {
        $path = Dump::writeDefaults(Dump::defaults('127.0.0.1', '3306', 'root', self::PASSWORD));

        try {
            self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
            self::assertStringContainsString(self::PASSWORD, (string) file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testTheFileNameSortsChronologically(): void
    {
        $dump = new Dump('mysqldump', 'trip-builder');

        self::assertSame('trip-builder-2026-09-12-184100.sql.gz', $dump->fileName('2026-09-12-184100'));
        self::assertLessThan(
            $dump->fileName('2026-09-13-000000'),
            $dump->fileName('2026-09-12-235959'),
        );
    }

    public function testAnAbsentBinaryIsReportedRatherThanRun(): void
    {
        self::assertFalse(Dump::isAvailable('mysqldump-that-does-not-exist'));
    }
}
