<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use DateTimeImmutable;
use Throwable;
use TripBuilder\Cron;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\Horizon;
use TripBuilder\View\Tone;

/**
 * What this site can honestly say about itself.
 *
 * **An operations dashboard and not a business one**, and that was measured
 * rather than chosen. The numbers here are lopsided: 429,233 flights, 197
 * searches, four scheduled commands running nightly, fifteen articles -- and
 * zero bookings, zero subscribers, zero votes. A panel that opened with revenue
 * would open with four zeros. So the first thing it answers is *is the machine
 * running*, which is the question the one operator actually has (A3.6, #231).
 *
 * Two rules the whole file obeys:
 *
 * **Nothing is invented.** Every reading below is a row somebody can go and
 * count. A figure nobody can trace is worse than four that they can, and this
 * is the file where a plausible-looking estimate would get in.
 *
 * **Nothing is answered from a cache.** A dashboard reporting a cached number
 * is reporting on the cache. Measured, the whole page is one 41ms query and
 * about a dozen 1ms ones -- see `flightsHeld()` for the expensive one and why
 * it is paid rather than avoided.
 *
 * Every reading is its own private method and the public lists are one line
 * each, so adding one is a method and a line rather than an edit to a template.
 *
 * `topSearches()`'s raw row is verified live: `SUM(search_count)` over the
 * INT column comes back `string`, same as `CityRepository`'s aggregates, not
 * the `int` the existing `(int)` cast might suggest it already was.
 *
 * @phpstan-type TopSearchRow array{from_code: string, to_code: string, runs: string, last: string}
 */
final readonly class DashboardRepository
{
    /** What counts as a fresh rate before the panel starts saying so. */
    private const int RATES_STALE_DAYS = 2;

    public function __construct(private Connection $connection) {}

    /**
     * The strip: four states, in words, with a tone behind each.
     *
     * States and not numbers, because "is it running" is not a quantity. The
     * schedule reading is passed in rather than read here -- `Schedule` already
     * knows how to age a run against its own cron, and a second opinion on that
     * would be a second thing to keep in step with the health endpoint.
     *
     * @param array<string, array{age: string, stale: bool}> $schedule
     * @return list<array{label: string, value: string, note: string, tone: Tone}>
     */
    public function states(array $schedule): array
    {
        return [
            $this->scheduleState($schedule),
            $this->flightReach(),
            $this->ratesState(),
            $this->databaseState(),
        ];
    }

    /**
     * The counts: how much of each thing there is.
     *
     * A null value means the table is empty, and the panel says "none yet"
     * rather than `0`. They read the same to a machine and differently to a
     * person: one is a measurement and the other is a site nobody has used.
     *
     * @return list<array{label: string, value: ?string, note: string}>
     */
    public function counts(): array
    {
        return [
            $this->flightsHeld(),
            $this->searches(),
            $this->bookings(),
            $this->subscribers(),
        ];
    }

    /**
     * Every scheduled command, with what the records say about it.
     *
     * The dense surface, in the sense the good panels use the phrase: the
     * strip above summarises this, and this is the thing an operator actually
     * reads when the summary is not green.
     *
     * @param array<string, array{age: string, stale: bool}> $schedule
     * @param list<array{command: string, cron: Cron}> $tasks
     * @return list<array{command: string, due: string, age: string, exit: ?int, stale: bool}>
     */
    public function schedule(array $schedule, array $tasks): array
    {
        $records = new ScheduleRunRepository($this->connection)->all();
        $rows = [];

        foreach ($tasks as $task) {
            $seen = $schedule[$task['command']] ?? ['age' => 'never', 'stale' => true];

            $rows[] = [
                'command' => $task['command'],
                // The crontab line itself. An operator reading this page is one
                // `crontab -e` away from the same five fields, and any prettier
                // rendering would be a second spelling to keep in step.
                'due' => $task['cron']->expression(),
                'age' => $seen['age'],
                'exit' => isset($records[$task['command']]) ? $records[$task['command']]['last_exit'] : null,
                'stale' => $seen['stale'],
            ];
        }

        return $rows;
    }

    /**
     * The content, counted the way the panel next door lists it.
     *
     * `edited_at` is A3.4's (#102) column, and it is worth a line here: it is
     * the number that says how much of the help text the next
     * `articles:import` will leave alone.
     *
     * @return array{articles: int, categories: int, hidden: int, owned: int}
     */
    public function content(): array
    {
        return [
            'articles' => $this->count(Table::Articles->value),
            'categories' => $this->count(Table::ArticleCategories->value),
            'hidden' => $this->count(Table::Articles->value, 'enabled = 0'),
            'owned' => $this->count(Table::Articles->value, 'edited_at IS NOT NULL'),
        ];
    }

    /**
     * What people looked for, most first.
     *
     * **Grouped by route, which the table is not.** `search` is keyed by a hash
     * of the whole search -- the pair, the dates, the cabin, the trip type --
     * so the same city pair has a row per set of dates somebody tried. Read
     * straight out, the list showed `YUL -> LHR` three times with three
     * different counts, which reads as a bug rather than as data.
     *
     * That shape is also why there is no chart anywhere on this page: the table
     * carries a count and a last-seen per search, not a row per search, so it
     * cannot answer "searches per day". A line that looked like it could would
     * be inventing one.
     *
     * @return list<array{from: string, to: string, count: int, last: string}>
     */
    public function topSearches(int $limit): array
    {
        /** @var list<TopSearchRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT from_code, to_code, SUM(search_count) AS runs, MAX(last_search) AS last'
            . ' FROM ' . Table::Search->value
            . ' GROUP BY from_code, to_code'
            . ' ORDER BY runs DESC, last DESC LIMIT ' . max(1, $limit),
        );

        return array_map(static fn(array $row): array => [
            'from' => (string) $row['from_code'],
            'to' => (string) $row['to_code'],
            'count' => (int) $row['runs'],
            'last' => (string) $row['last'],
        ], $rows);
    }

    /**
     * @param array<string, array{age: string, stale: bool}> $schedule
     * @return array{label: string, value: string, note: string, tone: Tone}
     */
    private function scheduleState(array $schedule): array
    {
        if ($schedule === []) {
            return ['label' => 'Schedule', 'value' => 'unknown', 'note' => 'nothing was read', 'tone' => Tone::Quiet];
        }

        $late = array_keys(array_filter($schedule, static fn(array $task): bool => $task['stale']));
        $total = count($schedule);

        if ($late === []) {
            return [
                'label' => 'Schedule',
                'value' => sprintf('%d of %d ran', $total, $total),
                'note' => 'all on time',
                'tone' => Tone::Good,
            ];
        }

        return [
            'label' => 'Schedule',
            'value' => sprintf('%d of %d late', count($late), $total),
            // Named, because "one is late" and "which one" are different
            // amounts of use to somebody about to go and look.
            'note' => implode(', ', array_slice($late, 0, 2)),
            'tone' => Tone::Bad,
        ];
    }

    /**
     * How far ahead the flights reach, against the horizon they should.
     *
     * `MAX(departure_time)` and not a count: measured at 1ms, because the
     * column is indexed. It is also the number that says whether the generator
     * is keeping up, which a count cannot -- a table can hold half a million
     * flights and still stop next Tuesday.
     *
     * @return array{label: string, value: string, note: string, tone: Tone}
     */
    private function flightReach(): array
    {
        $furthest = $this->connection->fetchValue(
            'SELECT MAX(departure_time) FROM ' . Table::Flights->value,
        );

        if (!is_string($furthest)) {
            return ['label' => 'Flights reach', 'value' => 'nowhere', 'note' => 'the table is empty', 'tone' => Tone::Bad];
        }

        $last = substr($furthest, 0, 10);
        $days = (int) (new DateTimeImmutable($last)->diff(new DateTimeImmutable(date('Y-m-d')))->days ?? 0);
        $ahead = $last >= date('Y-m-d') ? $days : 0;

        return [
            'label' => 'Flights reach',
            'value' => sprintf('%d days', $ahead),
            'note' => 'to ' . $last,
            // Two thirds of the horizon is the line: below that the generator
            // has stopped keeping up long enough to notice.
            'tone' => match (true) {
                $ahead >= Horizon::DAYS - 2 => Tone::Good,
                $ahead > (int) (Horizon::DAYS * 2 / 3) => Tone::Warn,
                default => Tone::Bad,
            },
        ];
    }

    /** @return array{label: string, value: string, note: string, tone: Tone} */
    private function ratesState(): array
    {
        $published = new CurrencyRateRepository($this->connection)->latestDate();

        if ($published === null) {
            return ['label' => 'Rates', 'value' => 'built in', 'note' => 'never fetched', 'tone' => Tone::Warn];
        }

        $days = (int) (new DateTimeImmutable($published)->diff(new DateTimeImmutable(date('Y-m-d')))->days ?? 0);

        return [
            'label' => 'Rates',
            'value' => $days === 0 ? 'today' : sprintf('%d day%s old', $days, $days === 1 ? '' : 's'),
            'note' => 'ECB ' . $published,
            // The ECB does not publish at weekends, so a two-day-old rate on a
            // Sunday is the newest one there is rather than a fault.
            'tone' => $days <= self::RATES_STALE_DAYS ? Tone::Good : Tone::Warn,
        ];
    }

    /**
     * That the database answered, and that it agrees with PHP about the time.
     *
     * Reaching this method at all means the connection worked -- the page could
     * not have been drawn otherwise -- so what is left worth reporting is the
     * clock, which can be wrong while nothing errors.
     *
     * @return array{label: string, value: string, note: string, tone: Tone}
     */
    private function databaseState(): array
    {
        $drift = null;

        try {
            $drift = $this->connection->fetchValue(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), ?)',
                [date('Y-m-d H:i:s')],
            );
        } catch (Throwable) {
            return ['label' => 'Database', 'value' => 'not answering', 'note' => 'the clock could not be read', 'tone' => Tone::Bad];
        }

        $seconds = abs((int) $drift);

        return [
            'label' => 'Database',
            'value' => 'answering',
            'note' => $seconds <= 2 ? 'clock in step' : sprintf('clock %ds apart', $seconds),
            'tone' => $seconds <= 2 ? Tone::Good : Tone::Warn,
        ];
    }

    /**
     * The one expensive reading on the page, and it is paid rather than
     * avoided.
     *
     * Measured: `COUNT(*)` over 429,233 rows is 41ms, where
     * `information_schema.TABLE_ROWS` answers in 1ms. The estimate was tried
     * and rejected -- InnoDB's row estimate can be far out, and a dashboard is
     * the last place to print a number that is *nearly* right. Forty
     * milliseconds on a page one person opens is not worth a lie.
     *
     * @return array{label: string, value: ?string, note: string}
     */
    private function flightsHeld(): array
    {
        $held = $this->count(Table::Flights->value);

        return [
            'label' => 'Flights held',
            'value' => $held === 0 ? null : number_format($held),
            'note' => 'counted, not estimated',
        ];
    }

    /** @return array{label: string, value: ?string, note: string} */
    private function searches(): array
    {
        $run = (int) $this->connection->fetchValue(
            'SELECT COALESCE(SUM(search_count), 0) FROM ' . Table::Search->value,
        );

        return [
            'label' => 'Searches',
            'value' => $run === 0 ? null : number_format($run),
            // The distinct count, because the table is an aggregate and the two
            // numbers answer different questions.
            'note' => sprintf('%s distinct', number_format($this->count(Table::Search->value))),
        ];
    }

    /** @return array{label: string, value: ?string, note: string} */
    private function bookings(): array
    {
        $made = $this->count(Table::Bookings->value);

        return [
            'label' => 'Bookings',
            'value' => $made === 0 ? null : number_format($made),
            'note' => sprintf('%s passenger(s)', number_format($this->count(Table::BookingPassengers->value))),
        ];
    }

    /** @return array{label: string, value: ?string, note: string} */
    private function subscribers(): array
    {
        $on = $this->count(Table::Subscribers->value);

        return [
            'label' => 'On the fare list',
            'value' => $on === 0 ? null : number_format($on),
            'note' => 'nothing sends to it yet',
        ];
    }

    /** One table's rows, optionally narrowed. */
    private function count(string $table, ?string $where = null): int
    {
        return (int) $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . $table . ($where === null ? '' : ' WHERE ' . $where),
        );
    }
}
