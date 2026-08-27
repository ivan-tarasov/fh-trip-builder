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
        // Four exec() shell-outs feed the footer on every page render; cache
        // them for the lifetime of the request.
        static $info = null;

        if ($info !== null) {
            return $info;
        }

        $git_branch = 'git rev-parse --abbrev-ref HEAD';
        $git_tag = 'git describe --tags --abbrev=0';
        $git_commitHash = 'git log --pretty="%h" -n1 HEAD';
        $git_commitDate = 'git log -n1 --pretty=%ci HEAD';

        $git_branch = trim(exec($git_branch));
        $git_tag = trim(exec($git_tag));
        $git_commitHash = trim(exec($git_commitHash));

        $git_commitDate = new DateTime(trim(exec($git_commitDate)));
        $git_commitDate->setTimezone(new DateTimeZone('UTC'));

        $info = [
            'branch' => $git_branch,
            'tag' => $git_tag,
            'commit_hash' => $git_commitHash,
            'commit_date' => $git_commitDate->format('Y-m-d H:i:s'),
        ];

        return $info;
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
}
