<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Noah;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TripBuilder\Helper;
use TripBuilder\Noah\Db\Migrate;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The migration ledger, exercised against a real database.
 *
 * The command itself needs a console harness this project does not have, so
 * these cover the part that carries the risk: that a migration is recorded once
 * and only once, and that a failure leaves no record behind for it to be
 * skipped by.
 */
final class MigrateTest extends IntegrationTestCase
{
    private const string TABLE = 'schema_migrations';

    protected function setUp(): void
    {
        // The command creates the ledger, and nothing else does -- not the table
        // configs, which is the point of it. So a test that reads the ledger has
        // to make sure the command has run, rather than assume someone ran it by
        // hand: on a database that has never seen `noah db:migrate` there is no
        // table to read, and the tests below run before the ones that make one.
        if (!$this->ledgerExists()) {
            $this->runMigration('test_' . uniqid(), []);
        }
    }

    protected function tearDown(): void
    {
        // Guarded: an early failure can leave the ledger absent, and cleaning up
        // after it should not raise a second, less useful error on the way out.
        if ($this->ledgerExists()) {
            $this->connection()->execute(
                'DELETE FROM ' . self::TABLE . " WHERE version LIKE 'test\\_%'",
            );
        }

        $this->connection()->pdo()->exec('DROP TABLE IF EXISTS _migrate_probe');
    }

    private function ledgerExists(): bool
    {
        return (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ?',
            [self::TABLE],
        ) > 0;
    }

    public function testTheLedgerExistsOnceMigrateHasRun(): void
    {
        // Created by the command rather than declared as a table config: it has
        // to exist before anything can be recorded in it. setUp() runs the
        // command, so this asserts the command made the table -- where before it
        // asserted that somebody had, and passed only on a database where they
        // had already done so.
        self::assertTrue($this->ledgerExists(), 'db:migrate did not create the ledger');
    }

    public function testAVersionIsRecordedOnlyOnce(): void
    {
        $version = 'test_' . uniqid();

        $this->connection()->execute(
            'INSERT INTO ' . self::TABLE . ' (version, applied_at) VALUES (?, NOW())',
            [$version],
        );

        // The primary key is what stops a migration running twice; without it
        // a second run would re-apply every ALTER in the directory.
        $this->expectExceptionMessageMatches('/Duplicate entry|Integrity constraint/i');

        $this->connection()->execute(
            'INSERT INTO ' . self::TABLE . ' (version, applied_at) VALUES (?, NOW())',
            [$version],
        );
    }

    /**
     * Write a migration, run the command, clean the file up either way.
     *
     * @param list<string> $statements
     */
    private function runMigration(string $version, array $statements): int
    {
        $file = Helper::getRootDir() . '/config/noah/db/migrations/' . $version . '.php';
        file_put_contents($file, "<?php\n\nreturn " . var_export($statements, true) . ";\n");

        try {
            $tester = new CommandTester(new Migrate());

            return $tester->execute([]);
        } finally {
            unlink($file);
        }
    }

    private function timesRecorded(string $version): int
    {
        return (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE version = ?',
            [$version],
        );
    }

    public function testAMigrationRunsAndIsRecorded(): void
    {
        $version = 'test_' . uniqid();

        $status = $this->runMigration($version, ['CREATE TABLE _migrate_probe (id INT)']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->timesRecorded($version));
        self::assertTrue($this->tableExists('_migrate_probe'), 'the statement did not run');
    }

    public function testAFailedMigrationIsNotRecordedSoItCanBeRerun(): void
    {
        // Recorded only once every statement has succeeded. A half-applied
        // migration that recorded itself would be skipped forever, leaving the
        // schema in a state nothing can describe.
        $version = 'test_' . uniqid();

        $status = $this->runMigration($version, [
            'CREATE TABLE _migrate_probe (id INT)',
            'ALTER TABLE _migrate_probe ADD COLUMN nope NOSUCHTYPE',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(0, $this->timesRecorded($version));
        // The first statement did land -- MySQL commits DDL implicitly, which
        // is why a migration has to be safe to run again rather than rely on a
        // rollback that cannot happen.
        self::assertTrue($this->tableExists('_migrate_probe'));
    }

    public function testAnAppliedMigrationDoesNotRunTwice(): void
    {
        $version = 'test_' . uniqid();

        self::assertSame(Command::SUCCESS, $this->runMigration($version, ['CREATE TABLE _migrate_probe (id INT)']));
        $this->connection()->pdo()->exec('DROP TABLE _migrate_probe');

        // Same version, a statement that would fail if it ran again.
        self::assertSame(Command::SUCCESS, $this->runMigration($version, ['NOT VALID SQL AT ALL']));
        self::assertSame(1, $this->timesRecorded($version));
        self::assertFalse($this->tableExists('_migrate_probe'), 'the migration ran a second time');
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection()->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        ) > 0;
    }
}
