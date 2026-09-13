<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTimeImmutable;
use RuntimeException;

/**
 * A crontab expression, parsed once and asked about a minute.
 *
 * `config/noah/schedule.php` used to carry a small vocabulary -- daily, hourly,
 * a time of day. Crontab is the notation everybody already reads, cPanel's own
 * editor is five fields in this order, and inventing a second spelling for the
 * same idea means two things to learn instead of one.
 *
 * It became worth having when the cron line moved to every minute. Before that
 * the outer tick was a fifteen-minute floor, so `*​/5` here could not have been
 * true whatever it said.
 *
 * **A subset, and it refuses what it does not implement.** Steps, ranges, lists
 * and `*` are here. Month and day names (`JAN`, `MON`), the `@daily` aliases,
 * and the Quartz extensions (`?`, `L`, `W`, `#`) are not, and a schedule using
 * one is rejected when it loads rather than silently read as something else. A
 * schedule that is quietly misunderstood is worse than one that will not start.
 */
final readonly class Cron
{
    /** minute, hour, day of month, month, day of week. */
    private const array RANGES = [
        [0, 59],
        [0, 23],
        [1, 31],
        [1, 12],
        [0, 7],
    ];

    /**
     * The five fields, named, in crontab order.
     *
     * Constants rather than bare strings so the config can use them: a
     * mistyped `Cron::MINTUE` is a fatal error on the line that wrote it, and a
     * mistyped `'mintue'` is a missing key reported somewhere else. Both are
     * caught -- `fromFields()` refuses an incomplete set -- but one of them
     * names the line.
     */
    public const string MINUTE = 'minute';
    public const string HOUR = 'hour';
    public const string DAY = 'day';
    public const string MONTH = 'month';
    public const string WEEKDAY = 'weekday';

    /** `*`, spelled so a config reads as a sentence. */
    public const string EVERY = '*';

    /** In the order a crontab line writes them, which is cPanel's order too. */
    public const array FIELDS = [self::MINUTE, self::HOUR, self::DAY, self::MONTH, self::WEEKDAY];

    private const int AT_MINUTE = 0;
    private const int AT_HOUR = 1;
    private const int AT_DAY = 2;
    private const int AT_MONTH = 3;
    private const int AT_WEEKDAY = 4;

    /**
     * @param list<array<int, true>> $allowed the minutes/hours/... each field permits
     * @param list<bool> $restricted whether each field was anything other than `*`
     */
    private function __construct(
        private array $allowed,
        private array $restricted,
        private string $expression,
    ) {}

    /**
     * Build from the five named fields, which is how the schedule writes them.
     *
     * Every field is required and none defaults to `*`. A schedule that can
     * omit a field is a schedule where forgetting one means "every", and
     * forgetting the day field turns a monthly task into a daily one without
     * anything looking wrong.
     *
     * @param array<string, string|int> $fields
     */
    public static function fromFields(array $fields, string $command = ''): self
    {
        $missing = array_values(array_diff(self::FIELDS, array_keys($fields)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                '%s is missing %s. All five of %s are required -- none of them defaults to `*`.',
                $command === '' ? 'A scheduled task' : '`' . $command . '`',
                implode(', ', $missing),
                implode(', ', self::FIELDS),
            ));
        }

        return self::parse(implode(' ', array_map(
            static fn(string $field): string => (string) $fields[$field],
            self::FIELDS,
        )));
    }

    public static function parse(string $expression): self
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) !== 5) {
            throw new RuntimeException(sprintf(
                '`%s` has %d field(s). A crontab expression has five: minute hour day month weekday.',
                $expression,
                count($fields),
            ));
        }

        $allowed = [];
        $restricted = [];

        foreach ($fields as $index => $field) {
            [$min, $max] = self::RANGES[$index];

            $allowed[] = self::values($field, $min, $max, $expression);
            $restricted[] = $field !== '*';
        }

        return new self($allowed, $restricted, trim($expression));
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * Whether this expression names this minute.
     *
     * Day-of-month and day-of-week are an **OR** when both are restricted, not
     * an AND. `0 3 13 * 5` is "the 13th, and every Friday", not "Friday the
     * 13th" -- which is the detail that is wrong for a year in a hand-rolled
     * parser, so it is stated here and tested.
     */
    public function matches(DateTimeImmutable $moment): bool
    {
        if (
            !isset($this->allowed[self::AT_MINUTE][(int) $moment->format('i')])
            || !isset($this->allowed[self::AT_HOUR][(int) $moment->format('G')])
            || !isset($this->allowed[self::AT_MONTH][(int) $moment->format('n')])
        ) {
            return false;
        }

        $dayOfMonth = isset($this->allowed[self::AT_DAY][(int) $moment->format('j')]);
        $dayOfWeek = isset($this->allowed[self::AT_WEEKDAY][(int) $moment->format('w')]);

        if ($this->restricted[self::AT_DAY] && $this->restricted[self::AT_WEEKDAY]) {
            return $dayOfMonth || $dayOfWeek;
        }

        return $dayOfMonth && $dayOfWeek;
    }

    /**
     * The most recent minute this names, at or before `$from`.
     *
     * Walks backwards a minute at a time rather than solving for it. The caller
     * bounds the walk by how long it is willing to look back, which in practice
     * is the gap since the task last ran -- a minute on an ordinary tick, and
     * the length of the outage after one.
     *
     * Null when nothing in that window matches, which is the honest answer: it
     * means "not due", not "due at some unknown time".
     */
    public function previous(DateTimeImmutable $from, int $withinMinutes): ?DateTimeImmutable
    {
        $moment = $from->setTime((int) $from->format('G'), (int) $from->format('i'));

        for ($i = 0; $i <= $withinMinutes; $i++) {
            if ($this->matches($moment)) {
                return $moment;
            }

            $moment = $moment->modify('-1 minute');
        }

        return null;
    }

    /**
     * @return array<int, true>
     */
    private static function values(string $field, int $min, int $max, string $expression): array
    {
        $values = [];

        foreach (explode(',', $field) as $term) {
            foreach (self::term($term, $min, $max, $expression) as $value) {
                // Sunday is both 0 and 7 in a crontab, and the two must mean
                // the same day rather than one of them matching nothing.
                $values[$value === 7 && $max === 7 ? 0 : $value] = true;
            }
        }

        return $values;
    }

    /**
     * @return list<int>
     */
    private static function term(string $term, int $min, int $max, string $expression): array
    {
        if (preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $term, $found) !== 1) {
            throw new RuntimeException(sprintf(
                '`%s` in `%s` is not something this understands. '
                . 'Supported: `*`, a number, `a-b`, a comma-separated list, and any of those with `/step`. '
                . 'Names, @aliases and the ? L W # extensions are not.',
                $term,
                $expression,
            ));
        }

        $step = isset($found[2]) ? (int) $found[2] : 1;

        if ($step < 1) {
            throw new RuntimeException(sprintf('`%s` in `%s` steps by zero.', $term, $expression));
        }

        [$from, $to] = match (true) {
            $found[1] === '*' => [$min, $max],
            str_contains($found[1], '-') => array_map(intval(...), explode('-', $found[1])),
            default => [(int) $found[1], isset($found[2]) ? $max : (int) $found[1]],
        };

        if ($from < $min || $to > $max || $from > $to) {
            throw new RuntimeException(sprintf(
                '`%s` in `%s` is outside %d-%d.',
                $term,
                $expression,
                $min,
                $max,
            ));
        }

        $values = [];

        for ($value = $from; $value <= $to; $value += $step) {
            $values[] = $value;
        }

        return $values;
    }
}
