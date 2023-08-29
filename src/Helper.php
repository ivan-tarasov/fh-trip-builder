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

    /**
     * @param      $number
     * @param      $singular
     * @param bool $showNumber
     * @return string
     */
    public static function plural($number, $singular, bool $showNumber = false): string
    {
        if ($number === 1) {
            $result = $singular;
        } else {
            $result = $singular . 's';
        }

        return $showNumber ? sprintf('%s %s', $number, $result) : $result;
    }

    /**
     * @param $id
     * @return string
     */
    public static function bookingIdToString($id): string
    {
        $numberStr = strval($id);
        $length = strlen($numberStr);

        $formattedNumber = '';
        for ($i = 0; $i < $length; $i++) {
            if ($i > 0 && ($length - $i) % 3 === 0) {
                $formattedNumber .= '-';
            }
            $formattedNumber .= $numberStr[$i];
        }

        return $formattedNumber;
    }

    /**
     * FIXME: should be rewrite
     *
     * Calculating taxes
     *
     * @param $amount
     * @param $province
     * @return mixed
     */
    public static function calculateTax($amount, $province = 'QC')
    {
        $tax = [
            // Newfoundland and Labrador
            'NL' => [
                'GST' => 0,
                'PST' => 0,
                'HST' => 15,
                'QST' => 0
            ],
            // Prince Edward Island
            'PE' => [
                'GST' => 0,
                'PST' => 0,
                'HST' => 15,
                'QST' => 0
            ],
            // Nova Scotia
            'NS' => [
                'GST' => 0,
                'PST' => 0,
                'HST' => 15,
                'QST' => 0
            ],
            // New Brunswick
            'NB' => [
                'GST' => 0,
                'PST' => 0,
                'HST' => 15,
                'QST' => 0
            ],
            // Quebec
            'QC' => [
                'GST' => 5,
                'PST' => 0,
                'HST' => 0,
                'QST' => 9.975
            ],
            // Ontario
            'ON' => [
                'GST' => 0,
                'PST' => 0,
                'HST' => 13,
                'QST' => 0
            ],
            // Manitoba
            'MB' => [
                'GST' => 5,
                'PST' => 7,
                'HST' => 0,
                'QST' => 0
            ],
            // Saskatchewan
            'SK' => [
                'GST' => 5,
                'PST' => 6,
                'HST' => 0,
                'QST' => 0
            ],
            // Alberta
            'AB' => [
                'GST' => 5,
                'PST' => 0,
                'HST' => 0,
                'QST' => 0
            ],
            // British Columbia
            'BC' => [
                'GST' => 5,
                'PST' => 7,
                'HST' => 0,
                'QST' => 0
            ],
            // Yukon
            'YT' => [
                'GST' => 5,
                'PST' => 0,
                'HST' => 0,
                'QST' => 0
            ],
            // Northwest Territories
            'NT' => [
                'GST' => 5,
                'PST' => 0,
                'HST' => 0,
                'QST' => 0
            ],
            // Nunavut
            'NU' => [
                'GST' => 5,
                'PST' => 0,
                'HST' => 0,
                'QST' => 0
            ],
        ];

        foreach ($tax[$province] as $abbr => $value) {
            if ($abbr === array_key_first($tax[$province])) {
                $tax[$province]['taxes'] = 0;
            }

            $total_tax = $amount / 100 * $value;
            $total_tax = number_format(round($total_tax, 2), 2);

            $tax[$province][$abbr] = $total_tax;

            if ($abbr !== array_key_last($tax[$province])) {
                $tax[$province]['taxes'] += $total_tax;
            }
        }

        $tax[$province]['total']  = number_format(round($amount + $tax[$province]['taxes'], 2), 2);
        $tax[$province]['amount'] = number_format(round($amount, 2), 2);
        $tax[$province]['taxes']  = number_format(round($tax[$province]['taxes'], 2), 2);

        return $tax[$province];
    }

}
