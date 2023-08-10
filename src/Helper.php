<?php

namespace TripBuilder;

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

}
