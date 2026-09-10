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
use TripBuilder\Repository\ArticleCategoryRepository;
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

    /** And of each category, below it, so one glob cannot pick up both. */
    private const string CATEGORY_DIR = 'config/content/help/categories';

    /** Keys an article's header must carry. */
    private const array REQUIRED = ['title', 'category', 'icon', 'position', 'summary'];

    /** And the one it may. */
    private const array OPTIONAL = ['short'];

    /**
     * Keys a category's header must carry, and the one it may.
     *
     * No `summary`: a category's sentence is the file's prose, not a header
     * value, so the fence reader refuses a category with nothing to say for
     * the same reason it refuses an article with no body.
     */
    private const array CATEGORY_REQUIRED = ['title', 'icon', 'position'];
    private const array CATEGORY_OPTIONAL = ['accent'];

    /** What article_category_translations.summary will hold. */
    private const int SUMMARY_LIMIT = 255;

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
        try {
            // Categories first: an article names one, and the check below is
            // worth nothing if it runs before the group it is checking for
            // could have been created.
            $categories = self::read(self::CATEGORY_DIR, self::parseCategory(...));
            $articles = self::read(self::CONTENT_DIR, self::parse(...));
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($categories === [] || $articles === []) {
            $this->io->error(sprintf(
                'Nothing to import: %s holds %d categor(y|ies) and %s holds %d article(s).',
                self::CATEGORY_DIR,
                count($categories),
                self::CONTENT_DIR,
                count($articles),
            ));

            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            foreach ($categories as $slug => $category) {
                $this->formatOutput(sprintf('%s (position %d)', $slug, $category['position']), 'category', 'info');
            }

            foreach ($articles as $slug => $article) {
                $this->formatOutput(
                    sprintf('%s (%s, position %d)', $slug, $article['category'], $article['position']),
                    'article',
                    'info',
                );
            }

            // Checked even here, because a dry run that reported five happy
            // articles and then failed on the sixth for a typo would be worse
            // than no dry run at all.
            $unknown = self::orphans($articles, array_keys($categories));

            if ($unknown !== []) {
                $this->io->error(implode("\n", $unknown));

                return Command::FAILURE;
            }

            $this->io->note(sprintf(
                '%d categor(y|ies) and %d article(s) parsed. Nothing written.',
                count($categories),
                count($articles),
            ));

            return Command::SUCCESS;
        }

        try {
            $connection = $this->connection();
            $categoryRepository = new ArticleCategoryRepository($connection);
            $repository = new ArticleRepository($connection);
        } catch (Throwable $e) {
            $this->io->error('No database: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $changed = 0;

        foreach ($categories as $slug => $category) {
            try {
                $moved = $categoryRepository->store(
                    $slug,
                    [
                        'icon' => $category['icon'],
                        'accent' => $category['accent'],
                        'position' => $category['position'],
                    ],
                    ['title' => $category['title'], 'summary' => $category['summary']],
                );
            } catch (Throwable $e) {
                $this->io->error(sprintf('%s: %s', $slug, $e->getMessage()));

                return Command::FAILURE;
            }

            $changed += $moved ? 1 : 0;
            $this->formatOutput($slug, $moved ? 'updated' : 'unchanged', $moved ? 'success' : 'info');
        }

        // Against the table and not against the files just read, so a category
        // that was renamed in a file but is still referenced by an article is
        // caught, and so is one that only exists because an earlier import put
        // it there. `slugs()` is unfiltered on purpose -- an article may
        // belong to a group that is written but held back.
        $unknown = self::orphans($articles, $categoryRepository->slugs());

        if ($unknown !== []) {
            $this->io->error(implode("\n", $unknown));

            return Command::FAILURE;
        }

        foreach ($articles as $slug => $article) {
            try {
                $moved = $repository->store(
                    $slug,
                    [
                        'category' => $article['category'],
                        'icon' => $article['icon'],
                        'position' => $article['position'],
                    ],
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
            '%d categor(y|ies) and %d article(s) imported, %d changed.',
            count($categories),
            count($articles),
            $changed,
        ));

        return Command::SUCCESS;
    }

    /**
     * Every `.md` file in one directory, parsed and keyed by slug.
     *
     * @param callable(string): array<string, mixed> $parse
     * @return array<string, array<string, mixed>>
     */
    private static function read(string $directory, callable $parse): array
    {
        $path = Helper::getRootDir() . '/' . $directory;
        $found = [];

        foreach (glob($path . '/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            $contents = @file_get_contents($file);

            if ($contents === false) {
                throw new RuntimeException(sprintf('Could not read %s.', $file));
            }

            try {
                $found[$slug] = $parse($contents);
            } catch (Throwable $e) {
                // Named, because "invalid front matter" across a dozen files
                // is a message that sends somebody looking through all of them.
                throw new RuntimeException(sprintf('%s/%s.md: %s', $directory, $slug, $e->getMessage()));
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Articles pointing at a category that is not there, named one per line.
     *
     * @param array<string, array<string, mixed>> $articles
     * @param list<string> $known
     * @return list<string>
     */
    private static function orphans(array $articles, array $known): array
    {
        $unknown = [];

        foreach ($articles as $slug => $article) {
            if (!in_array($article['category'], $known, true)) {
                $unknown[] = sprintf(
                    '%s.md names category `%s`, which does not exist. Known: %s.',
                    $slug,
                    (string) $article['category'],
                    implode(', ', $known) ?: '(none)',
                );
            }
        }

        return $unknown;
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
     * @return array{title: string, short: ?string, category: string, icon: string, position: int, summary: string, body: string}
     */
    public static function parse(string $contents): array
    {
        ['fields' => $fields, 'body' => $body] = self::header($contents, self::REQUIRED, self::OPTIONAL);

        return [
            'title' => $fields['title'],
            'short' => $fields['short'] ?? null,
            'category' => $fields['category'],
            'icon' => $fields['icon'],
            'position' => self::position($fields['position']),
            'summary' => $fields['summary'],
            'body' => $body,
        ];
    }

    /**
     * One category file, split the same way.
     *
     * The prose becomes the summary rather than a body of its own, because a
     * category has one sentence and nowhere to put a second. Length is checked
     * here and not left to the column: with STRICT_TRANS_TABLES a long
     * paragraph is a SQL error naming a column, where this names the file and
     * says what to do about it.
     *
     * @return array{title: string, icon: string, accent: string, position: int, summary: string}
     */
    public static function parseCategory(string $contents): array
    {
        ['fields' => $fields, 'body' => $body] = self::header(
            $contents,
            self::CATEGORY_REQUIRED,
            self::CATEGORY_OPTIONAL,
        );

        if (mb_strlen($body) > self::SUMMARY_LIMIT) {
            throw new RuntimeException(sprintf(
                'the description is %d characters and the column holds %d -- it is one sentence, not a page',
                mb_strlen($body),
                self::SUMMARY_LIMIT,
            ));
        }

        return [
            'title' => $fields['title'],
            'icon' => $fields['icon'],
            'accent' => $fields['accent'] ?? ArticleCategoryRepository::DEFAULT_ACCENT,
            'position' => self::position($fields['position']),
            'summary' => $body,
        ];
    }

    /**
     * `position` as a number, or a refusal naming what was written instead.
     */
    private static function position(string $value): int
    {
        if (preg_match('/^\d+\z/', $value) !== 1) {
            throw new RuntimeException(sprintf('position must be a whole number, not `%s`', $value));
        }

        return (int) $value;
    }

    /**
     * The fenced header and the prose under it, for either kind of file.
     *
     * Shared so both kinds refuse the same way. Every check here is a way a
     * broken file becomes a broken page rather than an error: a missing
     * summary would leave a page with no meta description and no lead; a
     * missing title would leave the heading and the breadcrumb blank; an
     * unknown key is a typo that would otherwise be silently dropped, which is
     * how `postion: 20` ends up sorting an article to the front.
     *
     * @param list<string> $required
     * @param list<string> $optional
     * @return array{fields: array<string, string>, body: string}
     */
    private static function header(string $contents, array $required, array $optional): array
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

            if (!in_array($key, [...$required, ...$optional], true)) {
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

        foreach ($required as $key) {
            if (!isset($fields[$key])) {
                throw new RuntimeException(sprintf('header is missing `%s`', $key));
            }
        }

        return ['fields' => $fields, 'body' => $body];
    }
}
