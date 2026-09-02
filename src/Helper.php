<?php

declare(strict_types=1);

namespace TripBuilder;

use DateTime;
use DateTimeZone;
use Exception;

class Helper
{
    /**
     * Return the project root directory
     */
    public static function getRootDir(): string
    {
        return dirname(__FILE__, 2);
    }

    public static function getUrlPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return is_string($path) ? $path : '/';
    }

    public static function getUTCTime(int|float $offset): string
    {
        $hours = floor(abs($offset));
        $minutes = abs($offset) * 60 % 60;

        $sign = ($offset >= 0) ? '+' : '-';

        return sprintf("GMT%s%02d:%02d", $sign, $hours, $minutes);
    }


    public static function getGitRepo(string $source = 'github'): string
    {
        $url = Config::get("git.$source.url");
        $user = Config::get("git.$source.user");
        $repo = Config::get("git.$source.repo");

        return "https://$url/$user/$repo";
    }

    /**
     * @throws Exception
     */
    public static function getGitInfo(): array
    {
        // Four exec() shell-outs feed the footer on every page render — about
        // 200ms of process spawning. The answer only changes when the checkout
        // does, so it is cached to disk and refreshed when git's own state
        // changes; the static keeps it to one read per request.
        static $info = null;

        if ($info !== null) {
            return $info;
        }

        $cacheFile = self::getRootDir() . '/cache/git-info.json';
        $stamp = self::gitStamp();

        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);

            if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp && isset($cached['info'])) {
                return $info = $cached['info'];
            }
        }

        $git_commitDate = new DateTime(trim(exec('git log -n1 --pretty=%ci HEAD')));
        $git_commitDate->setTimezone(new DateTimeZone('UTC'));

        $info = [
            'branch' => trim(exec('git rev-parse --abbrev-ref HEAD')),
            'tag' => trim(exec('git describe --tags --abbrev=0')),
            'commit_hash' => trim(exec('git log --pretty="%h" -n1 HEAD')),
            'commit_date' => $git_commitDate->format('Y-m-d H:i:s'),
        ];

        // A failure to cache only costs speed, never correctness.
        @file_put_contents($cacheFile, json_encode(['stamp' => $stamp, 'info' => $info]));

        return $info;
    }

    /**
     * A fingerprint of the checkout's current position: HEAD's contents plus
     * the modification time of the ref it points at, so committing, switching
     * branches or checking out all invalidate the cached git info.
     */
    private static function gitStamp(): string
    {
        $head = self::getRootDir() . '/.git/HEAD';

        if (!is_file($head)) {
            return 'no-git';
        }

        $contents = trim((string) file_get_contents($head));
        $stamp = $contents . ':' . filemtime($head);

        if (str_starts_with($contents, 'ref: ')) {
            $ref = self::getRootDir() . '/.git/' . trim(substr($contents, 5));

            if (is_file($ref)) {
                $stamp .= ':' . filemtime($ref);
            }
        }

        return $stamp;
    }

    /**
     * Return random number from provided range
     */
    public static function random(array $range): int
    {
        return rand($range[0], $range[1]);
    }

    public static function plural(
        int $number,
        string $singular,
        ?string $plural = null,
        bool $showNumber = false,
    ): string {
        $word = $number === 1 ? $singular : ($plural ?? "{$singular}s");
        return $showNumber ? "$number $word" : $word;
    }

    public static function bookingIdToString(int $id): string
    {
        return number_format($id, 0, '', '-');
    }

    public static function hoursAndMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $rest . 'm';
        }

        return $rest === 0 ? $hours . 'h' : sprintf('%dh %dm', $hours, $rest);
    }

    /**
     * What a range control's pill reads, naming the end that is actually set.
     *
     * On two handles the end matters: a floor that has been dragged up has to
     * say "From", or the same number would mean two opposite things. A `null`
     * ceiling is a single-handled control, which sits under a label that
     * already says which end it is, so the pill carries the number alone.
     * Mirrored by sliderCaption() in global.js, which repaints the pill as a
     * handle moves.
     */
    public static function sliderCaption(string $kind, int $from, ?int $to, int $min, int $max): string
    {
        $show = static fn(int $value): string => $kind === 'money'
            ? '$' . number_format($value)
            : self::hoursAndMinutes($value);

        if ($to === null) {
            return $show($from);
        }

        if ($from > $min && $to < $max) {
            return sprintf('From %s to %s', $show($from), $show($to));
        }

        return $from > $min ? 'From ' . $show($from) : 'Up to ' . $show($to);
    }

    /**
     * The scale a filter slider draws on: a readable step, and the ends snapped
     * onto it.
     *
     * Which way the bottom end rounds depends on what the handle there means.
     * A single handle is a ceiling, and a ceiling rounded *down* lands under
     * the cheapest thing on offer — "up to $6,000" over a $6,042 flight
     * matches nothing, and the far left of the track is the one place a
     * visitor is certain to drag to. A range has a floor down there instead,
     * and a floor under everything excludes nothing, so that one rounds down
     * and keeps the true spread visible.
     *
     * @param list<int> $steps allowed step sizes, smallest first
     * @param int $stops roughly how many positions the handle should have
     * @return array{min: int, max: int, step: int}
     */
    public static function sliderScale(
        int $low,
        int $high,
        array $steps,
        int $stops,
        bool $ceilingOnly,
    ): array {
        $step = self::sliderStep(max(1, $high - $low), $steps, $stops);

        $min = $ceilingOnly
            ? (int) ceil($low / $step) * $step
            : (int) floor($low / $step) * $step;
        $max = (int) ceil($high / $step) * $step;

        // One step wide at worst, so the track is draggable rather than a dot.
        return ['min' => $min, 'max' => max($max, $min + $step), 'step' => $step];
    }

    /**
     * The smallest allowed step that keeps the handle to roughly $stops
     * positions, so a slider is neither jumpy nor pixel-precise.
     *
     * @param list<int> $steps
     */
    public static function sliderStep(int $span, array $steps, int $stops): int
    {
        $wanted = max(1, (int) round($span / $stops));

        foreach ($steps as $step) {
            if ($wanted <= $step) {
                return $step;
            }
        }

        return $steps[count($steps) - 1];
    }

    /**
     * An airport's name with the city it is in stripped off the front.
     *
     * Where the two are shown together, "Amsterdam" above "Amsterdam Airport
     * Schiphol" stutters and costs a line of wrapping. Only a whole leading
     * word goes, or a city called "San" would maul "Santiago"; a name that is
     * nothing but the city leaves nothing behind, and no city strips nothing.
     */
    public static function airportNameAfterCity(string $title, string $city): string
    {
        if ($city === '') {
            return $title;
        }

        if (strcasecmp($title, $city) === 0) {
            return '';
        }

        $prefix = $city . ' ';

        return strncasecmp($title, $prefix, strlen($prefix)) === 0
            ? substr($title, strlen($prefix))
            : $title;
    }
}
