<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TripBuilder\Helper;
use TripBuilder\Repository\ScheduledJobRepository;
use TripBuilder\Schedule;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The live schedule, read from the database Run.php itself reads (G19, #377).
 *
 * `ScheduleTest` covers `Schedule` itself against plain arrays -- everything
 * here needs a real `scheduled_jobs` table, seeded once by
 * `config/noah/db/migrations/0008_seed_scheduled_jobs.php`.
 */
final class ScheduledJobRepositoryTest extends IntegrationTestCase
{
    private const string COMMAND = 'zz:probe --flag';

    /** @var list<int> */
    private array $created = [];

    protected function tearDown(): void
    {
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ($this->created as $id) {
            $this->connection()->execute('DELETE FROM scheduled_jobs WHERE id = ?', [$id]);
        }
    }

    private function jobs(): ScheduledJobRepository
    {
        return new ScheduledJobRepository($this->connection());
    }

    public function testACreatedJobIsFoundByItsCommand(): void
    {
        $jobs = $this->jobs();
        $id = $this->insert($jobs);

        self::assertTrue($jobs->commandExists(self::COMMAND));
        self::assertFalse($jobs->commandExists(self::COMMAND, $id), 'a job should not collide with itself');

        $found = $jobs->find($id);

        self::assertNotNull($found);
        self::assertSame(self::COMMAND, $found['command']);
        self::assertSame('0', $found['minute']);
        self::assertTrue($found['enabled']);
    }

    public function testUpdateReplacesEveryField(): void
    {
        $jobs = $this->jobs();
        $id = $this->insert($jobs);

        $jobs->update($id, self::COMMAND, '15', '4', '*', '*', '*', false);

        $found = $jobs->find($id);

        self::assertNotNull($found);
        self::assertSame('15', $found['minute']);
        self::assertSame('4', $found['hour']);
        self::assertFalse($found['enabled']);
    }

    /**
     * A disabled job is still listed and still editable -- it just drops out
     * of what actually runs.
     */
    public function testADisabledJobIsKeptButNotEnabled(): void
    {
        $jobs = $this->jobs();
        $id = $this->insert($jobs);
        $jobs->setEnabled($id, false);

        self::assertContains(self::COMMAND, array_column($jobs->all(), 'command'), 'all() dropped a disabled job');
        self::assertNotContains(
            self::COMMAND,
            array_column($jobs->allEnabled(), 'command'),
            'allEnabled() kept a disabled job',
        );
    }

    public function testRemoveDeletesTheRow(): void
    {
        $jobs = $this->jobs();
        $id = $this->insert($jobs);
        $jobs->remove($id);

        self::assertNull($jobs->find($id));
        $this->created = array_values(array_diff($this->created, [$id]));
    }

    /**
     * Every scheduled command is a command that exists.
     *
     * This is the failure this whole design invites: a typo in the schedule is
     * not a syntax error, not a test failure and not a deploy failure -- it is
     * a command that silently never runs, discovered weeks later by noticing
     * that rates are stale. Checked against the `AsCommand` names on disk, the
     * same guard `ScheduleTest` gave up when `config/noah/schedule.php` did.
     */
    public function testEveryScheduledCommandExists(): void
    {
        $registered = [];

        foreach (self::filesUnder(Helper::getRootDir() . '/src/Noah') as $file) {
            // Reads the `NAME` constant every command class declares, not the
            // `#[AsCommand(name: ...)]` attribute directly -- that argument is
            // `self::NAME` now, precisely so this string exists in one place.
            if (preg_match("/public const string NAME = '([^']+)';/", (string) file_get_contents($file), $found) === 1) {
                $registered[] = $found[1];
            }
        }

        self::assertNotEmpty($registered, 'No commands were found to check against.');

        foreach ($this->jobs()->allEnabled() as $job) {
            $name = explode(' ', $job['command'])[0];

            self::assertContains($name, $registered, sprintf(
                '`%s` is scheduled and does not exist. It would fail every night, quietly.',
                $name,
            ));
        }
    }

    /**
     * The migration ran, and what it seeded builds into a real schedule.
     */
    public function testTheRealScheduleLoads(): void
    {
        $tasks = Schedule::fromRows($this->jobs()->allEnabled())->tasks();

        self::assertNotEmpty($tasks, 'scheduled_jobs is empty -- did db:migrate run?');
    }

    /** @return list<string> */
    private static function filesUnder(string $directory): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function insert(ScheduledJobRepository $jobs): int
    {
        $id = $jobs->create(self::COMMAND, '0', '3', '*', '*', '*', true);
        $this->created[] = $id;

        return $id;
    }
}
