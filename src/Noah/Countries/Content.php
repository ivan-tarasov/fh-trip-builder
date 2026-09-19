<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Countries;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Noah\Wikipedia\Summary;
use TripBuilder\Repository\CountryContentRepository;
use TripBuilder\Repository\CountryRepository;

/**
 * One Wikipedia summary per country -- description-first (C17, #409),
 * unlike `cities:content`' photo-first shape (C10, #395): no image
 * pipeline, just the text. Built on `Summary` (C16, #408), the shared
 * fetch/backoff/disambiguation-detection logic `cities:content` built
 * first and this reuses rather than hand-copies a second time.
 *
 * Every sellable country (`CountryRepository::sellable()`, ~93 of them)
 * rather than a lazy per-request build, the same reasoning
 * `cities:content` uses: the set is small and fixed, so there is a real
 * "done" state to reach.
 *
 * A country is looked up again once it is older than `STALE_AFTER_DAYS`,
 * or always with `--force`. Recorded even when Wikipedia has nothing --
 * the same "recorded even when nothing was found" idiom `cities:content`
 * already uses, so a country with no summary is not asked about again
 * until it is next due.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Cache a Wikipedia summary for every sellable country.',
    aliases: [],
    hidden: false,
)]
class Content extends AbstractCommand
{
    public const string NAME = 'countries:content';

    private const string OPT_DRY_RUN = 'dry-run';
    private const string OPT_DRY_RUN_DESCRIPTION = 'List the countries that would be looked up without asking Wikipedia.';

    private const string OPT_FORCE = 'force';
    private const string OPT_FORCE_DESCRIPTION = 'Look up every country again, ignoring how recently it was last checked.';

    /** No arguments -- everything here is a flag. */
    public const array ARGUMENTS = [];

    /** Every option this command takes, name => description. */
    public const array OPTIONS = [
        self::OPT_DRY_RUN => self::OPT_DRY_RUN_DESCRIPTION,
        self::OPT_FORCE => self::OPT_FORCE_DESCRIPTION,
    ];

    /**
     * A courtesy pause between requests, the same value `cities:content`
     * measured live against Wikipedia's own IP-based rate limit --
     * `Summary::lookUp()` is the same source, so the same pacing applies.
     */
    private const int REQUEST_PAUSE_MICROSECONDS = 200_000;

    /**
     * Same reasoning as `Cities\Content::STALE_AFTER_DAYS`: a country's Wikipedia
     * summary is effectively static, and a short TTL would mean
     * re-asking a rate-limited source about ~93 countries for no real
     * gain.
     */
    private const int STALE_AFTER_DAYS = 90;

    /**
     * A country whose plain name does not resolve to its own Wikipedia
     * article -- built the same way `Cities\Content::WIKIPEDIA_TITLE_OVERRIDES`
     * was, checked live against every country this command actually
     * found nothing for, not guessed at ahead of time.
     */
    private const array WIKIPEDIA_TITLE_OVERRIDES = [
        'RE' => 'Réunion',
    ];

    /**
     * What `processCountry()` returns instead of `null` when the page it
     * landed on was a disambiguation page with no entry in
     * `WIKIPEDIA_TITLE_OVERRIDES` -- stored the same as a confirmed
     * no-summary answer, but counted and printed separately, the same
     * reason `Cities\Content::AMBIGUOUS` is.
     */
    private const string AMBIGUOUS = 'ambiguous';

    protected function configure(): void
    {
        $this->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, self::OPT_DRY_RUN_DESCRIPTION);
        $this->addOption(self::OPT_FORCE, null, InputOption::VALUE_NONE, self::OPT_FORCE_DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $content = new CountryContentRepository($this->connection());
        $force = (bool) $input->getOption(self::OPT_FORCE);

        $countries = new CountryRepository($this->connection())->sellable();

        if (!$force) {
            $due = array_flip($content->staleOrMissing(
                array_map(static fn(array $country): string => (string) $country['code'], $countries),
                self::STALE_AFTER_DAYS,
            ));

            $countries = array_values(array_filter(
                $countries,
                static fn(array $country): bool => isset($due[(string) $country['code']]),
            ));
        }

        $this->formatOutput('Countries to look up', number_format(count($countries)), 'info');

        if ($input->getOption(self::OPT_DRY_RUN)) {
            foreach ($countries as $country) {
                $this->io->writeln(sprintf(' %s -- %s', $country['code'], $country['name']));
            }

            $this->io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $wikipedia = new Summary('fh-trip-builder countries:content');

        $found = 0;
        $noSummary = 0;
        $skipped = 0;
        $ambiguous = 0;

        $progress = $this->io->createProgressBar(count($countries));
        $progress->start();

        foreach ($countries as $i => $country) {
            $outcome = $this->processCountry($content, $wikipedia, (string) $country['code'], (string) $country['name']);

            match ($outcome) {
                Summary::TRANSIENT => $skipped++,
                self::AMBIGUOUS => $ambiguous++,
                null => $noSummary++,
                default => $found++,
            };

            $progress->advance();

            // Skipped on the last country so the command does not end by
            // waiting on nothing -- the same reason `cities:content` skips
            // it too.
            if ($i < count($countries) - 1) {
                usleep(self::REQUEST_PAUSE_MICROSECONDS);
            }
        }

        $progress->finish();
        $this->io->newLine(2);

        $this->formatOutput('Summaries found', number_format($found), 'info');
        $this->formatOutput('No summary on Wikipedia', number_format($noSummary), 'comment');
        $this->formatOutput('Landed on a disambiguation page (add it to WIKIPEDIA_TITLE_OVERRIDES)', number_format($ambiguous), $ambiguous > 0 ? 'danger' : 'info');
        $this->formatOutput('Skipped (try again next run)', number_format($skipped), $skipped > 0 ? 'comment' : 'info', true);

        return Command::SUCCESS;
    }

    /**
     * One country, start to finish: ask Wikipedia, store the answer.
     *
     * @return string|null|self::AMBIGUOUS|Summary::TRANSIENT the extract
     *     stored, null for a confirmed no-summary answer,
     *     `self::AMBIGUOUS` for a disambiguation page with no override
     *     (also stored as null -- there is still nothing to show -- but
     *     counted apart so it is not mistaken for a real no-summary
     *     answer), or `Summary::TRANSIENT` to try again next run
     */
    private function processCountry(CountryContentRepository $content, Summary $wikipedia, string $countryCode, string $countryName): ?string
    {
        $title = self::WIKIPEDIA_TITLE_OVERRIDES[$countryCode] ?? $countryName;
        $summary = $wikipedia->lookUp($title);

        if ($summary === Summary::TRANSIENT) {
            return Summary::TRANSIENT;
        }

        $content->store($countryCode, $summary['extract']);

        return $summary['ambiguous'] ? self::AMBIGUOUS : $summary['extract'];
    }
}
