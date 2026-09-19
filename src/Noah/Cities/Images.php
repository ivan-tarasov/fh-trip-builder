<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Cities;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TripBuilder\Aws\ObjectStore;
use TripBuilder\Aws\S3;
use TripBuilder\Config;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\CityImageRepository;
use TripBuilder\Repository\CityRepository;

/**
 * One photo and one summary paragraph per city, from Wikipedia's own REST
 * summary endpoint -- see `config/noah/db/tables/city_images.php` for why
 * this is a cache table rather than a live call. C10 (#395)'s "Travel
 * deals" carousel reads the photo; C15 (#405) is the planned reader for
 * the text.
 *
 * The photo itself is downloaded and re-hosted under our own S3 key
 * (`Noah\Aws\S3`, the same store `PostImageUploader` writes to) rather
 * than linked straight to Wikipedia's CDN, so a page never depends on a
 * third party's hotlinking policy or uptime to render.
 *
 * Every real city (`CityRepository::all()`, ~231 of them) rather than a
 * lazy per-request build: unlike a route, the set of cities is small and
 * fixed, so there is a real "done" state to reach -- one pass warms
 * everywhere the carousel could ever land, rather than warming routes one
 * request at a time forever.
 *
 * A city is looked up again once it is older than `STALE_AFTER_DAYS`, or
 * always with `--force`. Recorded even when Wikipedia has nothing (a real
 * 404, or a page with no thumbnail) -- the same "recorded even when
 * nothing was found" idiom `RoutePriceRepository::build()` already uses,
 * so a city with no photo is not asked about again until it is next due.
 * A *transient* failure (a timeout, a 5xx, a dropped connection, or a
 * downloaded "photo" this could not decode) is answered neither yes nor
 * no and is left unrecorded on purpose, so the next run tries it again
 * rather than treating a bad moment as a permanent answer.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Cache a Wikipedia photo and summary for every real city.',
    aliases: [],
    hidden: false,
)]
class Images extends AbstractCommand
{
    public const string NAME = 'cities:images';

    private const string OPT_DRY_RUN = 'dry-run';
    private const string OPT_DRY_RUN_DESCRIPTION = 'List the cities that would be looked up without asking Wikipedia.';

    private const string OPT_FORCE = 'force';
    private const string OPT_FORCE_DESCRIPTION = 'Look up every city again, ignoring how recently it was last checked.';

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
     * How long a cached answer is trusted before it is asked for again. A
     * city's Wikipedia photo and summary are effectively static -- there is
     * no event in this app that would need a fresher answer than this --
     * and a short TTL would mean re-asking a rate-limited source about 231
     * cities for no real gain. `--force` bypasses this entirely.
     */
    private const int STALE_AFTER_DAYS = 90;

    /**
     * A city whose plain name is also a president, a state, or a common
     * placename shared by several other real cities does not resolve to
     * its own Wikipedia article -- it resolves to a disambiguation page,
     * which has no thumbnail and no useful extract. Live-found: `NYC` and
     * `WAS` (New York, Washington) both landed here, and a check of every
     * city already looked up at the time found the same thing for `PHX`,
     * `PDX` and `CLT` (Phoenix, Portland, Charlotte) -- five of the
     * seventeen cities this command had filed as "no photo" were actually
     * this, not a real absence.
     *
     * A general disambiguation resolver would mean fetching and parsing
     * the disambiguation page's own links, which is a second, heavier
     * Wikipedia call for every one of these with no guarantee the first
     * link is the right city. A short, hand-verified map of the exact
     * title that *is* the city is simpler and correct for as long as the
     * list of real cities stays the bounded, slow-changing set it is.
     * `lookUp()` still flags an unmapped disambiguation page rather than
     * silently filing it as "no photo", so a new collision in the other
     * cities not yet checked is something this command's own output
     * says out loud instead of a wrong answer nobody notices.
     */
    private const array WIKIPEDIA_TITLE_OVERRIDES = [
        'NYC' => 'New York City',
        'WAS' => 'Washington, D.C.',
        'PHX' => 'Phoenix, Arizona',
        'PDX' => 'Portland, Oregon',
        'CLT' => 'Charlotte, North Carolina',
    ];

    /**
     * A day, not `ObjectStore::IMMUTABLE`: unlike `PostImageUploader`'s
     * keys, this one does not carry a content hash, so the same key can
     * legitimately hold different bytes after a refresh. `IMMUTABLE` would
     * tell every CDN and browser in between never to ask again.
     */
    private const string CACHE_CONTROL = 'public, max-age=86400';

    /**
     * A sentinel `lookUp()`/`download()` can return instead of a real
     * answer: "could not ask (or could not use what came back) this run" --
     * see the class docblock for why this is stored differently from a
     * confirmed no-photo answer. A string rather than a real tri-state so
     * the calling code can `match` on one type instead of juggling `null`
     * for two different meanings.
     */
    private const string TRANSIENT = 'transient';

    /**
     * What `processCity()` returns instead of `null` when the page it
     * landed on was a disambiguation page with no entry in
     * `WIKIPEDIA_TITLE_OVERRIDES` -- stored the same as a confirmed
     * no-photo answer (there is still no real one to give), but counted
     * and printed separately so this is discoverable from the command's
     * own output rather than indistinguishable from a city that
     * genuinely has no Wikipedia photo.
     */
    private const string AMBIGUOUS = 'ambiguous';

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
            $due = array_flip($images->staleOrMissing(
                array_map(static fn(array $city): string => (string) $city['code'], $cities),
                self::STALE_AFTER_DAYS,
            ));

            $cities = array_values(array_filter(
                $cities,
                static fn(array $city): bool => isset($due[(string) $city['code']]),
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

        $store = S3::fromEnvironment();

        $found = 0;
        $noPhoto = 0;
        $skipped = 0;
        $ambiguous = 0;

        $progress = $this->io->createProgressBar(count($cities));
        $progress->start();

        foreach ($cities as $i => $city) {
            $outcome = $this->processCity($images, $store, (string) $city['code'], (string) $city['name']);

            match ($outcome) {
                self::TRANSIENT => $skipped++,
                self::AMBIGUOUS => $ambiguous++,
                null => $noPhoto++,
                default => $found++,
            };

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
        $this->formatOutput('Landed on a disambiguation page (add it to WIKIPEDIA_TITLE_OVERRIDES)', number_format($ambiguous), $ambiguous > 0 ? 'danger' : 'info');
        $this->formatOutput('Skipped (try again next run)', number_format($skipped), $skipped > 0 ? 'comment' : 'info', true);

        return Command::SUCCESS;
    }

    /**
     * One city, start to finish: ask Wikipedia, decide whether the photo
     * needs re-downloading, and store the answer.
     *
     * @return string|null|self::AMBIGUOUS|self::TRANSIENT the S3 key
     *     stored, null for a confirmed no-photo answer, `self::AMBIGUOUS`
     *     for a disambiguation page with no override (also stored as
     *     null -- there is still nothing to show -- but counted apart so
     *     it is not mistaken for a real no-photo answer), or
     *     `self::TRANSIENT` to try again next run
     */
    private function processCity(CityImageRepository $images, ObjectStore $store, string $cityCode, string $cityName): ?string
    {
        $existing = $images->find($cityCode);
        $summary = $this->lookUp($cityCode, $cityName);

        if ($summary === self::TRANSIENT) {
            return self::TRANSIENT;
        }

        $imageKey = $existing['image_key'] ?? null;
        $sourceUrl = $existing['image_source_url'] ?? null;

        if ($summary['source'] === null) {
            $imageKey = null;
            $sourceUrl = null;
        } elseif ($summary['source'] !== $sourceUrl) {
            // First time, or Wikipedia's own photo changed since we last
            // checked -- either way, the bytes we have (if any) are not
            // the bytes this answer names, so go get the real ones.
            $imageKey = $this->download($store, $cityCode, $summary['source']);

            if ($imageKey === self::TRANSIENT) {
                return self::TRANSIENT;
            }

            $sourceUrl = $summary['source'];
        }

        // else: the same photo as last time -- keep the key already on S3
        // rather than spending a download and an upload on identical bytes.

        $images->store($cityCode, $imageKey, $sourceUrl, $summary['extract']);

        return $summary['ambiguous'] ? self::AMBIGUOUS : $imageKey;
    }

    /**
     * Wikipedia's answer for one city.
     *
     * One retry, and only for a `429` -- live-measured, this is a real,
     * IP-based limit Wikipedia enforces and names with its own
     * `Retry-After` header, not noise: a batch of requests that ran clean
     * in isolation started failing consistently once it hit some request
     * count, and every failure was a `429` carrying that header. Waiting
     * it out once is worth doing since the answer says exactly how long;
     * a second `429` after that is left transient rather than waited out
     * again, so one throttled city cannot stall the whole run.
     *
     * `$cityCode` picks the title actually requested: an entry in
     * `WIKIPEDIA_TITLE_OVERRIDES` when this city has one, the plain name
     * otherwise -- see that constant for why a handful of real cities
     * need it.
     *
     * @return self::TRANSIENT|array{source: string|null, extract: string|null, ambiguous: bool}
     */
    private function lookUp(string $cityCode, string $cityName): array|string
    {
        $title = self::WIKIPEDIA_TITLE_OVERRIDES[$cityCode] ?? $cityName;
        $url = sprintf(self::ENDPOINT, rawurlencode(str_replace(' ', '_', $title)));

        $response = $this->request($url);

        if ($response['status'] === 429 && $response['retryAfter'] !== null) {
            sleep(min($response['retryAfter'], self::MAX_BACKOFF_SECONDS));
            $response = $this->request($url);
        }

        // A real "no such page" -- confirmed, not transient.
        if ($response['status'] === 404) {
            return ['source' => null, 'extract' => null, 'ambiguous' => false];
        }

        if ($response['body'] === null || $response['status'] !== 200) {
            return self::TRANSIENT;
        }

        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            return self::TRANSIENT;
        }

        // Wikipedia's own signal for "this title is not one article" -- a
        // disambiguation page's own `extract` is a list of what the name
        // could mean ("Washington most commonly refers to: ..."), not a
        // summary of any real place, so it is worth no more than the
        // thumbnail it also does not have.
        $ambiguous = ($data['type'] ?? null) === 'disambiguation';

        /** @var mixed $thumbnail */
        $thumbnail = $ambiguous ? null : ($data['thumbnail']['source'] ?? null);
        /** @var mixed $extract */
        $extract = $ambiguous ? null : ($data['extract'] ?? null);

        return [
            'source' => is_string($thumbnail) ? $thumbnail : null,
            'extract' => is_string($extract) && $extract !== '' ? $extract : null,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Download the photo at `$sourceUrl` and hand it to our own bucket
     * under this city's key, overwriting whatever was there. Overwriting
     * is correct here: the key is per-city, not per-content, so a changed
     * photo simply replaces the old bytes at the same address rather than
     * needing a new one.
     *
     * @return string|self::TRANSIENT the key stored, or TRANSIENT when the
     *     bytes could not be fetched or read as an image this run
     */
    private function download(ObjectStore $store, string $cityCode, string $sourceUrl): string
    {
        $response = $this->request($sourceUrl);

        if ($response['status'] !== 200 || $response['body'] === null) {
            return self::TRANSIENT;
        }

        $size = getimagesizefromstring($response['body']);

        if ($size === false) {
            return self::TRANSIENT;
        }

        $key = Config::get('site.static.endpoint.cities', 'images/cities')
            . '/' . $cityCode . '.' . image_type_to_extension($size[2], false);

        $store->put($key, $response['body'], (string) $size['mime'], self::CACHE_CONTROL);

        return $key;
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
