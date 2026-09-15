<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use DateTimeImmutable;
use TripBuilder\Helper;
use TripBuilder\Repository\DashboardRepository;
use TripBuilder\Repository\ScheduleRunRepository;
use TripBuilder\Schedule;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\View\Tone;

/**
 * The dashboard says only what the tables can answer.
 *
 * That is the rule the whole page is built on and it is the one worth a test,
 * because breaking it is silent: a figure that has quietly become an estimate,
 * or a `0` where the honest answer is "nobody has done this yet", looks exactly
 * like a working dashboard (A3.6, #231).
 *
 * These read the real rows rather than fixtures. The question is whether *this*
 * install can report on itself, and a fixture would answer a different one.
 */
final class DashboardTest extends IntegrationTestCase
{
    public function testTheStripSaysFourThingsAndSaysThemInWords(): void
    {
        $states = $this->dashboard()->states($this->schedule()['health']);

        self::assertCount(4, $states);

        foreach ($states as $state) {
            self::assertNotSame('', $state['label']);
            // Every tile prints its state in words, so the tone is emphasis
            // rather than information -- which is what lets the colour go.
            self::assertNotSame('', $state['value']);
            self::assertInstanceOf(Tone::class, $state['tone']);
        }
    }

    /**
     * An empty table reads as "nobody has done this yet", not as zero.
     *
     * They are the same to a machine and different to a person: one is a
     * measurement and the other is a site nobody has used.
     */
    public function testACountNobodyHasYetIsNotZero(): void
    {
        $bookings = $this->sqlCount('SELECT COUNT(*) FROM bookings');
        $counts = $this->byLabel($this->dashboard()->counts());

        self::assertArrayHasKey('Bookings', $counts);

        if ($bookings === 0) {
            self::assertNull($counts['Bookings']['value'], 'an empty table should read as nothing, not as 0');
        } else {
            self::assertNotNull($counts['Bookings']['value']);
        }
    }

    /**
     * The expensive one is counted, not estimated.
     *
     * `information_schema.TABLE_ROWS` answers in 1ms where `COUNT(*)` takes 41,
     * and it can be far out. A dashboard is the last place to print a number
     * that is nearly right, so this pins that the figure is the real one.
     */
    public function testTheFlightsFigureIsTheRealCount(): void
    {
        $held = $this->sqlCount('SELECT COUNT(*) FROM flights');
        $counts = $this->byLabel($this->dashboard()->counts());

        self::assertArrayHasKey('Flights held', $counts);
        self::assertSame(
            $held === 0 ? null : number_format($held),
            $counts['Flights held']['value'],
        );
    }

    /**
     * Most searched is a list of routes, and `search` is not.
     *
     * The table is keyed by a hash of the whole search -- pair, dates, cabin,
     * trip type -- so the same city pair has a row per set of dates somebody
     * tried. Read straight out, the panel showed `YUL -> LHR` three times with
     * three different counts, which reads as a bug rather than as data.
     */
    public function testMostSearchedNamesEachRouteOnce(): void
    {
        $searches = $this->dashboard()->topSearches(5);

        if ($searches === []) {
            self::markTestSkipped('nothing has been searched on this install');
        }

        $routes = array_map(static fn(array $row): string => $row['from'] . $row['to'], $searches);

        self::assertSame($routes, array_values(array_unique($routes)), 'a route is listed twice');

        // And ordered, most first, which is the only reason to call it "most
        // searched".
        $counts = array_map(static fn(array $row): int => $row['count'], $searches);
        $sorted = $counts;
        rsort($sorted);

        self::assertSame($sorted, $counts);
    }

    /**
     * Every scheduled command is on the page, including the ones that have
     * never run.
     *
     * A command missing from this table is a command nobody is watching, which
     * is the failure the whole section exists to prevent.
     */
    public function testEveryScheduledCommandIsListed(): void
    {
        $schedule = $this->schedule();
        $rows = $this->dashboard()->schedule($schedule['health'], $schedule['tasks']);

        self::assertCount(count($schedule['tasks']), $rows);

        foreach ($rows as $row) {
            self::assertNotSame('', $row['command']);
            // The crontab line itself: an operator reading this is one
            // `crontab -e` away from the same five fields.
            self::assertMatchesRegularExpression('/^[\d*\/,\-]+( [\d*\/,\-]+){4}$/', $row['due']);
            self::assertNotSame('', $row['age']);
        }
    }

    /**
     * A command with no record shows as never run rather than as a success.
     */
    public function testACommandThatNeverRanIsNotReportedAsFine(): void
    {
        $schedule = $this->schedule();
        $records = new ScheduleRunRepository($this->connection())->all();
        $rows = $this->dashboard()->schedule($schedule['health'], $schedule['tasks']);

        foreach ($rows as $row) {
            if (!isset($records[$row['command']])) {
                self::assertNull($row['exit'], $row['command'] . ' has no record but reports an exit code');
                self::assertTrue($row['stale']);
            }
        }
    }

    /**
     * The content summary counts what the panel next door lists, including the
     * rows A3.4 (#102) gave to a person.
     */
    public function testTheContentSummaryMatchesTheTables(): void
    {
        $content = $this->dashboard()->content();

        self::assertSame(
            $this->sqlCount('SELECT COUNT(*) FROM articles'),
            $content['articles'],
        );
        self::assertSame(
            $this->sqlCount('SELECT COUNT(*) FROM article_categories'),
            $content['categories'],
        );
        self::assertSame(
            $this->sqlCount('SELECT COUNT(*) FROM articles WHERE edited_at IS NOT NULL'),
            $content['owned'],
        );
        self::assertLessThanOrEqual($content['articles'], $content['hidden']);
    }

    /**
     * Nothing here is answered from a cache.
     *
     * A dashboard reporting a cached number is reporting on the cache. Two
     * reads in a row have to cost the same, which is what says the second one
     * went and looked.
     */
    public function testTheWholePageIsReadEveryTime(): void
    {
        $dashboard = $this->dashboard();
        $schedule = $this->schedule()['health'];

        $before = $this->connection()->queryCount();
        $dashboard->states($schedule);
        $first = $this->connection()->queryCount() - $before;

        $before = $this->connection()->queryCount();
        $dashboard->states($schedule);
        $second = $this->connection()->queryCount() - $before;

        self::assertGreaterThan(0, $first);
        self::assertSame($first, $second, 'the second read was cheaper, so something was cached');
    }

    /**
     * @param list<array{label: string, value: ?string, note: string}> $counts
     * @return array<string, array{label: string, value: ?string, note: string}>
     */
    private function byLabel(array $counts): array
    {
        $keyed = [];

        foreach ($counts as $count) {
            $keyed[$count['label']] = $count;
        }

        return $keyed;
    }

    /**
     * @return array{health: array<string, array{age: string, stale: bool}>, tasks: list<array{command: string, cron: \TripBuilder\Cron}>}
     */
    private function schedule(): array
    {
        $schedule = Schedule::fromConfig(Helper::getRootDir() . '/config/noah/schedule.php');

        return [
            'health' => $schedule->health(
                new DateTimeImmutable(),
                new ScheduleRunRepository($this->connection())->all(),
            ),
            'tasks' => $schedule->tasks(),
        ];
    }

    private function dashboard(): DashboardRepository
    {
        return new DashboardRepository($this->connection());
    }

    private function sqlCount(string $sql): int
    {
        /** @var int $count */
        $count = $this->connection()->fetchValue($sql);

        return $count;
    }
}
