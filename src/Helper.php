<?php

namespace TripBuilder;

use TripBuilder\Debug\dBug;

class Helper
{
    /**
     * Return project root directory
     *
     * @return string
     */
    public static function getRootDir(): string
    {
        return dirname(__FILE__, 2);
    }

    /**
     * @return mixed
     */
    public static function getUrlPath(): mixed
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/')['path'];
    }

    /**
     * @param $offset
     * @return string
     */
    public static function getUTCTime($offset): string
    {
        $hours = floor(abs($offset));
        $minutes = abs($offset) * 60 % 60;

        $sign = ($offset >= 0) ? '+' : '-';

        return sprintf("GMT%s%02d:%02d", $sign, $hours, $minutes);
    }


    /**
     * @param string $source
     * @return string
     */
    public static function getGitRepo(string $source = 'github'): string
    {
        return sprintf(
            'https://%s/%s/%s',
            Config::get("git.$source.url"),
            Config::get("git.$source.user"),
            Config::get("git.$source.repo"),
        );
    }

    /**
     * Get git information
     *
     * @return array
     * @throws \Exception
     */
    public static function getGitInfo(): array
    {
        $git_branch     = 'git rev-parse --abbrev-ref HEAD';
        $git_tag        = 'git describe --tags --abbrev=0';
        $git_commitHash = 'git log --pretty="%h" -n1 HEAD';
        $git_commitDate = 'git log -n1 --pretty=%ci HEAD';

        $git_branch     = trim(exec($git_branch));
        $git_tag        = trim(exec($git_tag));
        $git_commitHash = trim(exec($git_commitHash));

        $git_commitDate = new \DateTime(trim(exec($git_commitDate)));
        $git_commitDate->setTimezone(new \DateTimeZone('UTC'));

        return [
            'branch'      => $git_branch,
            'tag'         => $git_tag,
            'commit_hash' => $git_commitHash,
            'commit_date' => $git_commitDate->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Return random number from provided range
     *
     * @param array $range
     * @return int
     */
    public static function random(array $range): int
    {
        return rand($range[0], $range[1]);
    }

}
