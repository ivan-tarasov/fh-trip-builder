<?php

namespace TripBuilder;

use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

class Helper
{
    /**
     * Return the project root directory
     */
    public static function getRootDir(): string
    {
        return dirname(__FILE__, 2);
    }

    public static function getUrlPath(): mixed
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/')['path'];
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
        $git_branch = 'git rev-parse --abbrev-ref HEAD';
        $git_tag = 'git describe --tags --abbrev=0';
        $git_commitHash = 'git log --pretty="%h" -n1 HEAD';
        $git_commitDate = 'git log -n1 --pretty=%ci HEAD';

        $git_branch = trim(exec($git_branch));
        $git_tag = trim(exec($git_tag));
        $git_commitHash = trim(exec($git_commitHash));

        $git_commitDate = new DateTime(trim(exec($git_commitDate)));
        $git_commitDate->setTimezone(new DateTimeZone('UTC'));

        return [
            'branch' => $git_branch,
            'tag' => $git_tag,
            'commit_hash' => $git_commitHash,
            'commit_date' => $git_commitDate->format('Y-m-d H:i:s')
        ];
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

    public static function calculateTax(float $amount, string $province = 'QC'): array
    {
        $rates = [
            'NL' => ['HST' => 15],
            'PE' => ['HST' => 15],
            'NS' => ['HST' => 15],
            'NB' => ['HST' => 15],
            'QC' => ['GST' => 5, 'QST' => 9.975],
            'ON' => ['HST' => 13],
            'MB' => ['GST' => 5, 'PST' => 7],
            'SK' => ['GST' => 5, 'PST' => 6],
            'AB' => ['GST' => 5],
            'BC' => ['GST' => 5, 'PST' => 7],
            'YT' => ['GST' => 5],
            'NT' => ['GST' => 5],
            'NU' => ['GST' => 5],
        ];

        if (!isset($rates[$province])) {
            throw new InvalidArgumentException("Unknown province: $province");
        }

        $taxes = [];
        $totalTax = 0.0;

        foreach ($rates[$province] as $abbr => $rate) {
            $value = round($amount * ($rate / 100), 2);
            $taxes[$abbr] = number_format($value, 2, '.', '');
            $totalTax += $value;
        }

        return [
                'amount' => number_format($amount, 2, '.', ''),
                'taxes' => number_format($totalTax, 2, '.', ''),
                'total' => number_format($amount + $totalTax, 2, '.', ''),
            ] + $taxes;
    }
}
