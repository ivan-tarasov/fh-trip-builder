<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Articles;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\ArticleRepository;

#[AsCommand(
    name: 'articles:import',
    description: 'Load the help articles from config/content/help into the database.',
    aliases: [],
    hidden: false,
)]

/**
 * The help articles, from files into rows.
 *
 * There is no seeder CSV for these, and that is the point rather than an
 * omission. `app:install` upserts every seeder and refreshes every column, so
 * a seeded article would be reverted to the committed copy on every install --
 * which is fine while nothing edits articles and wrong the moment something
 * does. currency_rates escaped that by keying on the date, so a seeded row and
 * a fetched row are different rows; article_votes escaped it by having no
 * seeder. An article's seed row and its edited row are the same row, so the
 * only way out is for the install not to touch it.
 *
 * So this is the one thing that writes articles, and it is run on purpose. A
 * fresh install therefore needs it -- see the README's install steps, which
 * were already a sequence of noah commands rather than one.
 *
 * Split the way currency:rates is split: `parse()` is static, pure, and takes
 * a string, so every judgement the command makes is tested against fixtures
 * with no filesystem and no database. What is left touches disk and the table
 * and is thin enough to read.
 *
 * The header is `key: value` lines between `---` fences. It looks like YAML
 * and deliberately is not: there is no YAML parser in this project, and adding
 * one would make a claim on the checkout confirmation page false -- it tells a
 * reader the dependencies are "console, twig and commonmark, and nothing
 * else", which is the reason it gives for sending no email. Five scalar keys
 * do not need a dependency.
 */
class Import extends AbstractCommand
{
    /** Where the committed copy of each article lives. */
    private const string CONTENT_DIR = 'config/content/help';

    /** Keys the header must carry. */
    private const array REQUIRED = ['title', 'icon', 'position', 'summary'];

    /** And the one it may. */
    private const array OPTIONAL = ['short'];

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Parse and report, without writing anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = Helper::getRootDir() . '/' . self::CONTENT_DIR;
        $files = glob($directory . '/*.md') ?: [];

        if ($files === []) {
            $this->io->error(sprintf('No articles found in %s.', self::CONTENT_DIR));

            return Command::FAILURE;
        }

        $parsed = [];

        foreach ($files as $file) {
            $slug = basename($file, '.md');
            $contents = @file_get_contents($file);

            if ($contents === false) {
                $this->io->error(sprintf('Could not read %s.', $file));

                return Command::FAILURE;
            }

            try {
                $parsed[$slug] = self::parse($contents);
            } catch (Throwable $e) {
                // Named, because "invalid front matter" in a five-file import
                // is a message that sends somebody looking through all five.
                $this->io->error(sprintf('%s.md: %s', $slug, $e->getMessage()));

                return Command::FAILURE;
            }
        }

        ksort($parsed);

        if ($input->getOption('dry-run')) {
            foreach ($parsed as $slug => $article) {
                $this->formatOutput(
                    sprintf('%s (position %d)', $slug, $article['position']),
                    'parsed',
                    'info',
                );
            }

            $this->io->note(sprintf('%d article(s) parsed. Nothing written.', count($parsed)));

            return Command::SUCCESS;
        }

        try {
            $repository = new ArticleRepository($this->connection());
        } catch (Throwable $e) {
            $this->io->error('No database: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $changed = 0;

        foreach ($parsed as $slug => $article) {
            try {
                $moved = $repository->store(
                    $slug,
                    ['icon' => $article['icon'], 'position' => $article['position']],
                    [
                        'title' => $article['title'],
                        'short' => $article['short'],
                        'summary' => $article['summary'],
                        'body' => $article['body'],
                    ],
                );
            } catch (Throwable $e) {
                $this->io->error(sprintf('%s: %s', $slug, $e->getMessage()));

                return Command::FAILURE;
            }

            $changed += $moved ? 1 : 0;
            $this->formatOutput($slug, $moved ? 'updated' : 'unchanged', $moved ? 'success' : 'info');
        }

        $this->io->success(sprintf(
            '%d article(s) imported, %d changed.',
            count($parsed),
            $changed,
        ));

        return Command::SUCCESS;
    }

    /**
     * One article file, split into its header and its prose.
     *
     * Every refusal here is a way a broken file becomes a broken page rather
     * than an error. A missing summary would leave a page with no meta
     * description and no lead; a missing title would leave the heading and the
     * breadcrumb blank; an unknown key is a typo that would otherwise be
     * silently dropped, which is how `postion: 20` ends up sorting an article
     * to the front.
     *
     * @return array{title: string, short: ?string, icon: string, position: int, summary: string, body: string}
     */
    public static function parse(string $contents): array
    {
        // Normalised first, so a file saved on Windows is not a parse error.
        $text = str_replace(["\r\n", "\r"], "\n", $contents);

        if (!str_starts_with($text, "---\n")) {
            throw new RuntimeException('no header: the file must open with a --- fence');
        }

        $end = strpos($text, "\n---", 3);

        if ($end === false) {
            throw new RuntimeException('the header is never closed by a --- fence');
        }

        $header = substr($text, 4, $end - 3);
        $body = trim(substr($text, $end + 4));

        if ($body === '') {
            throw new RuntimeException('no prose after the header');
        }

        $fields = [];

        foreach (explode("\n", trim($header)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                throw new RuntimeException(sprintf('header line is not `key: value`: %s', trim($line)));
            }

            $key = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));

            if (!in_array($key, [...self::REQUIRED, ...self::OPTIONAL], true)) {
                throw new RuntimeException(sprintf('unknown header key `%s`', $key));
            }

            if (isset($fields[$key])) {
                throw new RuntimeException(sprintf('header key `%s` appears twice', $key));
            }

            if ($value === '') {
                throw new RuntimeException(sprintf('header key `%s` has no value', $key));
            }

            $fields[$key] = $value;
        }

        foreach (self::REQUIRED as $key) {
            if (!isset($fields[$key])) {
                throw new RuntimeException(sprintf('header is missing `%s`', $key));
            }
        }

        if (preg_match('/^\d+\z/', $fields['position']) !== 1) {
            throw new RuntimeException(sprintf('position must be a whole number, not `%s`', $fields['position']));
        }

        return [
            'title' => $fields['title'],
            'short' => $fields['short'] ?? null,
            'icon' => $fields['icon'],
            'position' => (int) $fields['position'],
            'summary' => $fields['summary'],
            'body' => $body,
        ];
    }
}
