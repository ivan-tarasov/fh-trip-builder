<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Flights;

/**
 * Which days a generation run should fill, and how many each gets.
 *
 * `flights:add N` draws a random day in the window for every flight, which is
 * the right shape for filling an empty database and the wrong one for keeping
 * a window topped up. Run daily, it spreads the night's flights evenly over
 * ninety days -- so the day that has just entered the window at day ninety
 * starts empty, gains about `N / 90` a night, and would take three months to
 * reach the density of its neighbours, by which time it has left the window
 * again (E24.1, #191).
 *
 * So a scheduled run has to say *which* days. `level()` answers that by
 * filling the thinnest first, which is the shape that also recovers: a missed
 * night leaves two thin days rather than one, and the next run finds both
 * without being told they were missed.
 */
final readonly class DayPlan
{
    /**
     * Raise the thinnest days first, and stop when the budget runs out.
     *
     * Water-filling. The days are sorted by what they already hold; the lowest
     * are raised together to the next level up, then the next, until either
     * every day is equal or `$toAdd` is spent. What is left over after the last
     * whole level is spread one at a time from the thinnest, so the answer is
     * exact rather than approximately exact -- a caller asking for 2,328
     * flights gets 2,328.
     *
     * Deterministic in ties: days at the same count are raised in calendar
     * order, so two runs over the same table plan the same thing.
     *
     * @param list<string> $window every day to consider, `Y-m-d`, in order
     * @param array<string, int> $have what each day already holds; absent is none
     * @return array<string, int> day => flights to add, thinnest first, no zeroes
     */
    public static function level(array $window, array $have, int $toAdd): array
    {
        if ($window === [] || $toAdd < 1) {
            return [];
        }

        $counts = [];

        foreach ($window as $day) {
            $counts[$day] = max(0, $have[$day] ?? 0);
        }

        // Ascending by what the day holds, then by the day itself. asort keeps
        // the insertion order of equal values, and $window was in order.
        asort($counts);

        $days = array_keys($counts);
        $levels = array_values($counts);
        $added = array_fill_keys($days, 0);
        $budget = $toAdd;

        // How many days are still tied for thinnest when the budget runs out.
        // Every day, if the loop below levels the whole window.
        $pool = count($days);

        // Raise days[0..$i] to the level of days[$i + 1], one step at a time.
        for ($i = 0; $i < count($days) - 1 && $budget > 0; $i++) {
            $step = $levels[$i + 1] - $levels[$i];

            if ($step <= 0) {
                continue;
            }

            $cost = $step * ($i + 1);

            if ($cost > $budget) {
                $pool = $i + 1;

                break;
            }

            for ($k = 0; $k <= $i; $k++) {
                $added[$days[$k]] += $step;
                $levels[$k] += $step;
            }

            $budget -= $cost;
        }

        // Whatever the last whole level left, one at a time round the days
        // that are tied for thinnest -- and only those. Spreading it over the
        // whole window instead would hand flights to days that already have
        // more than their neighbours, which is the behaviour this exists to
        // replace.
        for ($k = 0; $budget > 0; $k = ($k + 1) % $pool) {
            $added[$days[$k]]++;
            $budget--;
        }

        return array_filter($added, static fn(int $n): bool => $n > 0);
    }

    /**
     * The days a window covers, `Y-m-d`, in order.
     *
     * @param array{0: int, 1: int} $offsets first and last day, relative to $from
     * @return list<string>
     */
    public static function window(string $from, array $offsets): array
    {
        $days = [];

        for ($offset = $offsets[0]; $offset <= $offsets[1]; $offset++) {
            $days[] = date('Y-m-d', (int) strtotime(sprintf('%s + %d days', $from, $offset)));
        }

        return $days;
    }
}
