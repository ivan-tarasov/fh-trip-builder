<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Cities;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\CityImageRepository;
use TripBuilder\Repository\CityRepository;

/**
 * One photo per city, from Wikipedia's own REST summary endpoint -- see
 * `config/noah/db/tables/city_images.php` for why this is a cache table
 * rather than a live call. C10 (#395)'s "Travel deals" carousel is the
 * first reader.
 *
 * Every real city (`CityRepository::all()`, ~231 of them) rather than a
 * lazy per-request build: unlike a route, the set of cities is small and
 * fixed, so there is a real "done" state to reach -- one pass warms
 * everywhere the carousel could ever land, rather than warming routes one
 * request at a time forever.
 *
 * A city already in the table is skipped unless `--force` is given.
 * Recorded even when Wikipedia has nothing (a real 404, or a page with no
 * thumbnail) -- the same "recorded even when nothing was found" idiom
 * `RoutePriceRepository::build()` already uses, so a city with no photo is
 * not asked about again every run. A *transient* failure (a timeout, a
 * 5xx, a dropped connection) is answered neither yes nor no and is left
 * unrecorded on purpose, so the next run tries it again rather than
 * treating a bad moment on Wikipedia's end as a permanent "no photo".
 */
#[AsCommand(
    name: self::NAME,
    description: 'Cache a Wikipedia photo for every real city.',
    aliases: [],
    hidden: false,
)]
class Images extends AbstractCommand
{
    public const string NAME = 'cities:images';

    private const string OPT_DRY_RUN = 'dry-run';
    private const string OPT_DRY_RUN_DESCRIPTION = 'List the cities that would be looked up without asking Wikipedia.';

    private const string OPT_FORCE = 'force';
    private const string OPT_FORCE_DESCRIPTION = 'Look up every city again, including ones already cached.';

    /** No arguments -- everything here is a flag. */
    public const array ARGUMENTS = [];

    /** Every option this command takes, name => description. */
    public const array OPTIONS = [
        self::OPT_DRY_RUN => self::OPT_DRY_RUN_DESCRIPTION,
        self::OPT_FORCE => self::OPT_FORCE_DESCRIPTION,
    ];

    private const string ENDPOINT = 'https://en.wikipedia.org/api/rest_v1/page/summary/%s';

    /** Long enough for a slow response, short enough not to hang the whole run on one city. */
    private const int TIMEOUT_SECONDS = 10;
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    /** See the pause's own comment in execute() for why this exists at all. */
    private const int REQUEST_PAUSE_MICROSECONDS = 200_000;

    /** A `Retry-After` this command will not sit and wait longer than. */
    private const int MAX_BACKOFF_SECONDS = 15;

    /**
     * A sentinel `lookUp()` can return instead of `null`: `null` is "asked,
     * confirmed no photo", this is "could not ask" -- see the class
     * docblock for why the two are stored differently. A string rather
     * than a real tri-state so the method's own return type can stay the
     * plain `?string` every caller already expects a URL lookup to have.
     */
    private const string TRANSIENT = 'transient';

    protected function configure(): void
    {
        $this->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, self::OPT_DRY_RUN_DESCRIPTION);
        $this->addOption(self::OPT_FORCE, null, InputOption::VALUE_NONE, self::OPT_FORCE_DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $images = new CityImageRepository($this->connection());
        $force = (bool) $input->getOption(self::OPT_FORCE);

        $cities = new CityRepository($this->connection())->all();

        if (!$force) {
            $cities = array_values(array_filter(
                $cities,
                static fn(array $city): bool => !$images->has((string) $city['code']),
            ));
        }

        $this->formatOutput('Cities to look up', number_format(count($cities)), 'info');

        if ($input->getOption(self::OPT_DRY_RUN)) {
            foreach ($cities as $city) {
                $this->io->writeln(sprintf(' %s -- %s', $city['code'], $city['name']));
            }

            $this->io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $found = 0;
        $noPhoto = 0;
        $skipped = 0;

        $progress = $this->io->createProgressBar(count($cities));
        $progress->start();

        foreach ($cities as $i => $city) {
            $answer = $this->lookUp((string) $city['name']);

            if ($answer === self::TRANSIENT) {
                $skipped++;
            } else {
                $images->store((string) $city['code'], $answer);
                $answer === null ? $noPhoto++ : $found++;
            }

            $progress->advance();

            // A courtesy pause, not a rate limit this app was told about --
            // measured live: a tight back-to-back loop over all 231 cities
            // failed roughly a third of them with no distinguishing status
            // or error (a plain curl of the very same URL immediately after,
            // alone, succeeded every time), and slowing down measurably
            // improved the pass rate. Skipped on the last city so the
            // command does not end by waiting on nothing.
            if ($i < count($cities) - 1) {
                usleep(self::REQUEST_PAUSE_MICROSECONDS);
            }
        }

        $progress->finish();
        $this->io->newLine(2);

        $this->formatOutput('Photos found', number_format($found), 'info');
        $this->formatOutput('No photo on Wikipedia', number_format($noPhoto), 'comment');
        $this->formatOutput('Skipped (try again next run)', number_format($skipped), $skipped > 0 ? 'comment' : 'info', true);

        return Command::SUCCESS;
    }

    /**
     * The image URL, `null` for a confirmed no-photo answer, or
     * `self::TRANSIENT` to try again next run.
     *
     * One retry, and only for a `429` -- live-measured, this is a real,
     * IP-based limit Wikipedia enforces and names with its own
     * `Retry-After` header, not noise: a batch of requests that ran clean
     * in isolation started failing consistently once it hit some request
     * count, and every failure was a `429` carrying that header. Waiting
     * it out once is worth doing since the answer says exactly how long;
     * a second `429` after that is left transient rather than waited out
     * again, so one throttled city cannot stall the whole run.
     */
    private function lookUp(string $cityName): ?string
    {
        $url = sprintf(self::ENDPOINT, rawurlencode(str_replace(' ', '_', $cityName)));

        $response = $this->request($url);

        if ($response['status'] === 429 && $response['retryAfter'] !== null) {
            sleep(min($response['retryAfter'], self::MAX_BACKOFF_SECONDS));
            $response = $this->request($url);
        }

        // A real "no such page" -- confirmed, not transient.
        if ($response['status'] === 404) {
            return null;
        }

        if ($response['body'] === null || $response['status'] !== 200) {
            return self::TRANSIENT;
        }

        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            return self::TRANSIENT;
        }

        /** @var mixed $thumbnail */
        $thumbnail = $data['thumbnail']['source'] ?? null;

        return is_string($thumbnail) ? $thumbnail : null;
    }

    /** @return array{status: int, body: string|null, retryAfter: int|null} */
    private function request(string $url): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return ['status' => 0, 'body' => null, 'retryAfter' => null];
        }

        $retryAfter = null;

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'fh-trip-builder cities:images',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // The only header this command reads. A closure over $retryAfter
            // rather than a second curl_getinfo() call: libcurl hands headers
            // to this as they arrive, not the response body's own parser.
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^retry-after:\s*(\d+)/i', $line, $match) === 1) {
                    $retryAfter = (int) $match[1];
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated
        // from 8.5, where it prints a notice over this command's own output.

        return [
            'status' => $status,
            'body' => is_string($body) && $error === '' ? $body : null,
            'retryAfter' => $retryAfter,
        ];
    }
}
