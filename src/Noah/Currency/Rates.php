<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Currency;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\CurrencyRateRepository;

#[AsCommand(
    name: self::NAME,
    description: 'Refresh the currency conversion rates from the European Central Bank.',
    aliases: [],
    hidden: false,
)]

/**
 * Fetch today's rates and store them against the day they were published.
 *
 * Split in two on purpose. `parse()` is static, pure and takes a string, so the
 * whole of the validation is tested against a committed fixture; `fetch()` is
 * the few lines that touch the network and are not tested at all. CI has the
 * curl extension but no promise of a route to the internet, and a command that
 * could only be tested by calling out would be a flaky test waiting to happen.
 *
 * Deliberately not wired into `app:install`. A deploy that cannot finish
 * because a third party is down is a worse deploy than one with yesterday's
 * rates, and the seeded rows mean a fresh install is never rate-less.
 *
 * Given a span it backfills instead: `currency:rates 2025-09-13..2026-09-13`
 * asks the source for every publication date in that range and writes them all.
 * The table was keyed `(code, rate_date)` from the start for this, and its
 * docblock said the backfill would be a command argument rather than a second
 * command -- so it is one (B2.1, #104).
 */
class Rates extends AbstractCommand
{
    public const string NAME = 'currency:rates';

    private const string ARG_SPAN = 'span';
    private const string ARG_SPAN_DESCRIPTION = 'A date or a range to backfill, as `2025-09-13` or'
        . ' `2025-09-13..2026-03-01`. Left out, the command fetches the latest rates and nothing else.';

    private const string OPT_DRY_RUN = 'dry-run';
    private const string OPT_DRY_RUN_DESCRIPTION = 'Fetch and check the rates, and report them without writing anything.';

    /** Every argument this command takes, name => description. */
    public const array ARGUMENTS = [self::ARG_SPAN => self::ARG_SPAN_DESCRIPTION];

    /** Every option this command takes, name => description. */
    public const array OPTIONS = [self::OPT_DRY_RUN => self::OPT_DRY_RUN_DESCRIPTION];

    /**
     * The source, with the span written into the path.
     *
     * `latest` for today and `start..end` for a range -- the service's own
     * syntax, so there is nothing to translate and nothing to get wrong in the
     * translating. Everything that reaches the `%s` has been through
     * `readSpan()` first; this is a URL, and the span comes from a command line.
     */
    private const string ENDPOINT = 'https://api.frankfurter.dev/v1/%s?base=CAD';

    /** Long enough for a slow morning, short enough not to hang a cron. */
    private const int TIMEOUT_SECONDS = 15;
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * The rate the base currency has against itself, which no service returns.
     *
     * The endpoint is asked for rates *relative to* CAD, so CAD is the one code
     * absent from its own response. Stored anyway, so the formatter reads the
     * default currency by the same path as every other one.
     */
    private const float BASE_RATE = 1.0;

    protected function configure(): void
    {
        $this->addArgument(self::ARG_SPAN, InputArgument::OPTIONAL, self::ARG_SPAN_DESCRIPTION);

        $this->addOption(
            self::OPT_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            self::OPT_DRY_RUN_DESCRIPTION,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The site config, which a console command does not otherwise have:
        // AbstractCommand loads the environment but constructs no Config, and
        // Install replaces the static one with table definitions. Without this
        // the catalogue reads as empty and the command "succeeds" having stored
        // nothing at all -- which is exactly what it did the first time it ran.
        new Config();

        $wanted = array_keys(Currency::all());

        if ($wanted === []) {
            $this->io->error(
                'No currencies are configured, so there is nothing to fetch rates for.'
                . ' config/common/currencies.php is missing or empty.',
            );

            return Command::FAILURE;
        }

        $span = $input->getArgument(self::ARG_SPAN);

        if (is_string($span) && $span !== '') {
            return $this->backfill($span, $wanted, (bool) $input->getOption(self::OPT_DRY_RUN));
        }

        try {
            $payload = self::parse($this->fetch(sprintf(self::ENDPOINT, 'latest')), $wanted);
        } catch (Throwable $e) {
            // Nothing has been written at this point, and nothing will be. A
            // half-applied refresh is worse than a failed one: it would leave
            // some currencies on today's rate and some on last week's, with a
            // fresh fetched_at claiming all of them were confirmed.
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->formatOutput('Rates published', $payload['date'], 'info');
        $this->formatOutput('Currencies read', (string) count($payload['rates']), 'info');

        if ($input->getOption(self::OPT_DRY_RUN)) {
            $this->io->note('Dry run: nothing written.');
            $this->io->listing(array_map(
                static fn(string $code): string => $code . ' ' . $payload['rates'][$code],
                array_keys($payload['rates']),
            ));

            return Command::SUCCESS;
        }

        try {
            $written = new CurrencyRateRepository($this->connection())
                ->store($payload['rates'], $payload['date']);
        } catch (Throwable $e) {
            $this->io->error('Storing the rates failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->formatOutput('Rates stored', (string) $written, 'success');

        return Command::SUCCESS;
    }

    /**
     * Every publication date in a span, written in one go.
     *
     * What B2.2's graph needs and what the table has always been shaped for:
     * one date is a point, and a point is not a chart.
     *
     * @param list<string> $wanted every code the catalogue offers, CAD included
     */
    private function backfill(string $span, array $wanted, bool $dryRun): int
    {
        try {
            $payload = self::parseRange($this->fetch(sprintf(self::ENDPOINT, self::readSpan($span))), $wanted);
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->formatOutput('Span published', $payload['start'] . ' to ' . $payload['end'], 'info');
        $this->formatOutput('Dates read', (string) count($payload['days']), 'info');

        // Named rather than counted, because a code the source has stopped
        // publishing and a code the catalogue spells wrong look identical in a
        // number. Not a failure: these are historic days, and refusing a year
        // of thirty currencies over one gap in one of them would be worse than
        // recording the gap.
        foreach ($payload['missing'] as $code => $days) {
            $this->formatOutput('No rate published', sprintf('%s, on %d date(s)', $code, $days), 'comment');
        }

        if ($dryRun) {
            $this->io->note('Dry run: nothing written.');

            return Command::SUCCESS;
        }

        try {
            $written = new CurrencyRateRepository($this->connection())->storeMany($payload['days']);
        } catch (Throwable $e) {
            $this->io->error('Storing the rates failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->formatOutput('Rates stored', (string) $written, 'success');

        return Command::SUCCESS;
    }

    /**
     * A span as the source spells it, or a refusal.
     *
     * This value comes off a command line and goes into a request path, so it
     * is matched against a shape and then checked as a real calendar date --
     * `2025-02-30` matches the shape and is not a day.
     *
     * A bare date means "from then until the latest", which is the common ask
     * and is the source's own open-ended form.
     *
     * Public and static for the same reason `parse()` is: it is the whole of
     * the judgement, it takes a string, and it is worth testing without a
     * network or a console.
     */
    public static function readSpan(string $span): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(\.\.(\d{4}-\d{2}-\d{2})?)?$/', $span, $parts) !== 1) {
            throw new RuntimeException(
                'A span reads as `2025-09-13` or `2025-09-13..2026-03-01`, and ' . var_export($span, true) . ' does not.',
            );
        }

        $start = $parts[1];
        $end = $parts[3] ?? '';

        foreach (array_filter([$start, $end]) as $date) {
            if (self::isNotADay($date)) {
                throw new RuntimeException($date . ' is not a day.');
            }
        }

        if ($end !== '' && $end < $start) {
            throw new RuntimeException('The span ends before it begins.');
        }

        if ($start > date('Y-m-d')) {
            throw new RuntimeException('The span begins in the future, and nobody has published those rates yet.');
        }

        return $start . '..' . $end;
    }

    /** Whether a `Y-m-d` string names a day that exists. */
    private static function isNotADay(string $date): bool
    {
        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        return !checkdate($month, $day, $year);
    }

    /**
     * Read a span of days, or refuse the payload.
     *
     * The refusals are `parse()`'s, for the same reasons -- a body that is not
     * JSON, quoted against the wrong base, or holding no rates cannot become
     * anything but wrong prices.
     *
     * What is deliberately *not* a refusal here is an incomplete day. `parse()`
     * throws on one because a truncated daily response would leave currencies
     * on an older figure while `fetched_at` claimed all of them were just
     * confirmed. A historic day is not that: the ECB really did stop publishing
     * some currencies for years at a time, and that is a fact to record rather
     * than an error to fail on. Each code keeps its own newest row, so a gap in
     * one currency cannot make another currency's price stale.
     *
     * A day holding nothing but the base is dropped, though. Storing it would
     * say the source published a day it did not.
     *
     * @param list<string> $wanted every code the catalogue offers, CAD included
     * @return array{start: string, end: string, days: array<string, array<string, float>>, missing: array<string, int>}
     */
    public static function parseRange(string $json, array $wanted): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('The rates response was not JSON.');
        }

        $base = $decoded['base'] ?? null;

        if ($base !== 'CAD') {
            throw new RuntimeException('The rates are quoted against ' . var_export($base, true) . ', not CAD.');
        }

        $published = $decoded['rates'] ?? null;

        if (!is_array($published) || $published === []) {
            throw new RuntimeException('The rates response held no dates.');
        }

        $days = [];
        $missing = [];

        foreach ($published as $date => $row) {
            if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !is_array($row)) {
                throw new RuntimeException('The rates are filed under something that is not a date.');
            }

            $rates = [];

            foreach ($wanted as $code) {
                if ($code === 'CAD') {
                    $rates[$code] = self::BASE_RATE;

                    continue;
                }

                $rate = $row[$code] ?? null;

                if ((!is_int($rate) && !is_float($rate)) || $rate <= 0) {
                    $missing[$code] = ($missing[$code] ?? 0) + 1;

                    continue;
                }

                $rates[$code] = (float) $rate;
            }

            if (count($rates) > 1) {
                $days[$date] = $rates;
            }
        }

        if ($days === []) {
            throw new RuntimeException('The span held no day with a usable rate in it.');
        }

        $dates = array_keys($days);

        return [
            // The source's own bounds where it gave them, and the days it
            // actually answered with otherwise -- it returns the last
            // publication on or before the start, so the two can differ.
            'start' => is_string($decoded['start_date'] ?? null) ? $decoded['start_date'] : (string) min($dates),
            'end' => is_string($decoded['end_date'] ?? null) ? $decoded['end_date'] : (string) max($dates),
            'days' => $days,
            'missing' => $missing,
        ];
    }

    /**
     * Read a payload, or refuse it.
     *
     * Every check here exists so that a bad response cannot become a bad price.
     * The completeness check is the important one: a truncated body would parse
     * as perfectly good JSON holding eleven of thirty rates, and storing those
     * would leave nineteen currencies quietly on an older figure while
     * `fetched_at` said all thirty had just been confirmed.
     *
     * A missing code is reported by name rather than counted, because the fix
     * is either the catalogue or the source and the operator needs to know
     * which currency to go and look at.
     *
     * @param list<string> $wanted every code the catalogue offers, CAD included
     * @return array{date: string, rates: array<string, float>}
     */
    public static function parse(string $json, array $wanted): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('The rates response was not JSON.');
        }

        $base = $decoded['base'] ?? null;

        if ($base !== 'CAD') {
            // Asking for one base and being answered in another would invert
            // every price on the site without erroring anywhere.
            throw new RuntimeException('The rates are quoted against ' . var_export($base, true) . ', not CAD.');
        }

        $date = $decoded['date'] ?? null;

        if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new RuntimeException('The rates carry no usable publication date.');
        }

        $published = $decoded['rates'] ?? null;

        if (!is_array($published) || $published === []) {
            throw new RuntimeException('The rates response held no rates.');
        }

        $rates = [];
        $missing = [];

        foreach ($wanted as $code) {
            if ($code === 'CAD') {
                $rates[$code] = self::BASE_RATE;

                continue;
            }

            $rate = $published[$code] ?? null;

            if (!is_int($rate) && !is_float($rate)) {
                $missing[] = $code;

                continue;
            }

            if ($rate <= 0) {
                // A rate of zero would make every price in that currency free.
                $missing[] = $code;

                continue;
            }

            $rates[$code] = (float) $rate;
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The source published no usable rate for %s. Nothing was stored. Either the'
                . ' catalogue in config/common/currencies.php offers a currency the ECB has'
                . ' stopped publishing, or the response was truncated.',
                implode(', ', $missing),
            ));
        }

        return ['date' => $date, 'rates' => $rates];
    }

    /**
     * The few lines that talk to the internet, and now the only ones in this
     * tool: Grab\Suppliers was the other, and it is gone.
     *
     * Which makes what is set below the standard rather than one command's
     * good manners -- a timeout, a connect timeout, a status check, and a
     * redirect that is refused rather than followed quietly.
     */
    private function fetch(string $url): string
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Could not start a request for the rates.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            // A redirect from a rates endpoint is not something to follow
            // quietly; it means the address in this file is wrong.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'fh-trip-builder currency:rates',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated
        // from 8.5, where it printed a notice over this command's own output.

        if (!is_string($body) || $error !== '') {
            throw new RuntimeException('The rates could not be fetched: ' . ($error !== '' ? $error : 'no response'));
        }

        if ($status !== 200) {
            throw new RuntimeException('The rates endpoint answered ' . $status . '.');
        }

        return $body;
    }
}
