<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Currency;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Config;
use TripBuilder\Currency;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\CurrencyRateRepository;

#[AsCommand(
    name: 'currency:rates',
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
 */
class Rates extends AbstractCommand
{
    private const string SOURCE = 'https://api.frankfurter.dev/v1/latest?base=CAD';

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
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Fetch and check the rates, and report them without writing anything.',
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

        try {
            $payload = self::parse($this->fetch(self::SOURCE), $wanted);
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

        if ($input->getOption('dry-run')) {
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
        curl_close($handle);

        if (!is_string($body) || $error !== '') {
            throw new RuntimeException('The rates could not be fetched: ' . ($error !== '' ? $error : 'no response'));
        }

        if ($status !== 200) {
            throw new RuntimeException('The rates endpoint answered ' . $status . '.');
        }

        return $body;
    }
}
