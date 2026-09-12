<?php

declare(strict_types=1);

namespace TripBuilder\Noah\Airside;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TripBuilder\Cdn;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;
use TripBuilder\Repository\PostImageRepository;
use TripBuilder\Repository\PostRepository;
use TripBuilder\Repository\PostTagRepository;
use TripBuilder\Service\ImageResizer;
use TripBuilder\Service\PostImageUploader;
use TripBuilder\View\Airside\PostImages;
use TripBuilder\View\Airside\PostImageSet;

#[AsCommand(
    name: 'airside:import',
    description: 'Make the Airside posts in the database match the files in config/content/airside.',
    aliases: [],
    hidden: false,
)]

/**
 * The Airside posts, from files into rows.
 *
 * The sibling of `articles:import`, and deliberately the same command: read
 * every file, refuse the whole run on a bad one, write what parsed, then delete
 * the rows no file describes any more. The files are the whole truth, which is
 * what stops a database that has already imported a post from keeping it after
 * the file is deleted while a fresh install never has it.
 *
 * What differs from help, and only this: a post carries a date it chooses
 * rather than a position somebody assigns, and it may carry a hero image.
 */
final class Import extends AbstractCommand
{
    private const string CONTENT_DIR = 'config/content/airside';

    /**
     * Where a hero image has to be, and the first images *this repository*
     * serves: there is no `public/img` before this and no template renders
     * an `<img>` from the checkout.
     *
     * Not the first images the site serves, which an earlier version of this
     * comment claimed. Carrier logos, supplier logos and the POI cards all
     * come from a CloudFront distribution through `Cdn::getUrl()`, which
     * reads `AWS_CLOUDFRONT` -- so the infrastructure for content images
     * mostly exists and the open question is whether these belong in it.
     *
     * That question is A8.6's. This is the smallest thing that lets a post
     * have a picture, and it is why the column holds the *file name* and not a
     * path: moving these to the CDN later is a change to one class rather than
     * to every row.
     */
    private const string IMAGE_DIR = PostImages::DIRECTORY;

    private const array REQUIRED = ['title', 'published', 'author', 'summary'];

    /**
     * `hero_alt` is optional only because `hero` is. Given one, the other is
     * required -- see `parse()`.
     */
    private const array OPTIONAL = ['hero', 'hero_alt', 'tags'];

    private const int TITLE_LIMIT = 120;
    private const int SUMMARY_LIMIT = 255;
    private const int ALT_LIMIT = 160;
    private const int TAG_LIMIT = 48;

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Parse and report, without writing anything.',
        );

        $this->addOption(
            'no-upload',
            null,
            InputOption::VALUE_NONE,
            'Write the rows but send nothing to the bucket.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $posts = self::read(self::CONTENT_DIR, self::parse(...));
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($posts === []) {
            // Also what stops a mistyped or unmounted directory emptying the
            // table: nothing is removed on a run that found nothing to keep.
            $this->io->error(sprintf(
                'Nothing to import: %s holds no .md files.',
                self::CONTENT_DIR,
            ));

            return Command::FAILURE;
        }

        // Before the connection, because nothing about it needs one: a post
        // naming an image that is not there now costs no writes at all.
        $missing = self::missingImages($posts);

        if ($missing !== []) {
            $this->io->error(implode("\n", $missing));

            return Command::FAILURE;
        }

        $clashes = self::tagNameClashes($posts);

        if ($clashes !== []) {
            $this->io->error(implode("\n", $clashes));

            return Command::FAILURE;
        }

        try {
            $connection = $this->connection();
            $repository = new PostRepository($connection);
            $tags = new PostTagRepository($connection);
            $images = new PostImageRepository($connection);
        } catch (Throwable $e) {
            $this->io->error('No database: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            foreach ($posts as $slug => $post) {
                $this->formatOutput(
                    sprintf('%s (%s)', $slug, $post['published_at']),
                    'post',
                    'info',
                );
            }

            // The half of a dry run that matters, since the command deletes. A
            // preview that stayed quiet about removals would preview only the
            // safe half.
            foreach (array_diff($repository->slugs(), array_keys($posts)) as $slug) {
                $this->formatOutput($slug, 'would remove', 'comment');
            }

            $this->io->note(sprintf('%d post(s) parsed. Nothing written.', count($posts)));

            return Command::SUCCESS;
        }

        // Only when there is a distribution to serve them from. With none,
        // `PostImages::url()` points at the staging directory and the files
        // are already where the pages look -- so a local import needs no
        // credential, and a deployed one cannot silently skip the upload.
        $uploader = null;

        if (!$input->getOption('no-upload') && Cdn::isConfigured()) {
            try {
                $uploader = PostImageUploader::fromEnvironment();
            } catch (Throwable $e) {
                $this->io->error('No uploader: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        $changed = 0;
        $uploaded = 0;

        foreach ($posts as $slug => $post) {
            // Before the row, so a post is never stored pointing at a picture
            // that is not there. The reverse order can fail halfway and leave
            // a page with a broken hero; this order fails halfway and leaves
            // unreferenced objects, which cost about nothing and are the same
            // bytes the next run would have sent anyway.
            try {
                // Recorded whether or not anything is uploaded. The page reads
                // these from the table in both cases -- with no distribution
                // the files are served from the staging directory, but the
                // markup still has to say how big they are.
                foreach (self::imageSizes($post) as $file => $size) {
                    $images->store($file, $size['width'], $size['height']);
                }

                $uploaded += count($uploader === null ? [] : self::uploadImages($uploader, $post));
            } catch (Throwable $e) {
                $this->io->error(sprintf('%s images: %s', $slug, $e->getMessage()));

                return Command::FAILURE;
            }

            try {
                $moved = $repository->store(
                    $slug,
                    [
                        'published_at' => $post['published_at'],
                        'author' => $post['author'],
                        // The staged file's name goes in the file; the row gets
                        // the canonical one, carrying a hash of the bytes. The
                        // author writes `hero: wing.jpg` and the row says
                        // `wing.3f9a2b1c.jpg`, which is what the page asks the
                        // distribution for.
                        'hero' => self::canonicalHero($post['hero']),
                    ],
                    [
                        'title' => $post['title'],
                        'summary' => $post['summary'],
                        'hero_alt' => $post['hero_alt'],
                        'body' => $post['body'],
                    ],
                );
            } catch (Throwable $e) {
                $this->io->error(sprintf('%s: %s', $slug, $e->getMessage()));

                return Command::FAILURE;
            }

            try {
                foreach ($post['tags'] as $tag => $name) {
                    $tags->store($tag, $name);
                }

                $tags->map($slug, array_keys($post['tags']));
            } catch (Throwable $e) {
                $this->io->error(sprintf('%s tags: %s', $slug, $e->getMessage()));

                return Command::FAILURE;
            }

            $changed += $moved ? 1 : 0;
            $this->formatOutput($slug, $moved ? 'updated' : 'unchanged', $moved ? 'success' : 'info');
        }

        try {
            $removed = 0;

            foreach (array_diff($repository->slugs(), array_keys($posts)) as $slug) {
                // The map first: a row pointing at a post that no longer exists
                // is a listing page offering a link to a 404.
                $tags->forget($slug);
                $repository->delete($slug);

                $removed++;
                $this->formatOutput($slug, 'removed', 'comment');
            }

            // And then the names nothing points at any more. After the loop, so
            // one run both removes a post and forgets the tag only it used.
            $tags->pruneUnused();
        } catch (Throwable $e) {
            $this->io->error('Could not remove what the files no longer describe: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->io->success(sprintf(
            '%d post(s) imported, %d changed, %d removed%s.',
            count($posts),
            $changed,
            $removed,
            $uploader === null ? '' : sprintf(', %d image(s) uploaded', $uploaded),
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

            // A file name is a URL here, so one the route cannot match is a
            // post nothing can reach -- and lower case specifically, because
            // AirsideController 301s a capital to lower case and then looks up
            // what it redirected to. Kept in step with the pattern in
            // Routes::DYNAMIC_ROUTES.
            if (preg_match('/^[a-z0-9-]+\z/', $slug) !== 1) {
                throw new RuntimeException(sprintf(
                    '%s/%s.md: a file name becomes the URL, so it can only hold'
                    . ' lower-case letters, digits and hyphens',
                    $directory,
                    $slug,
                ));
            }

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
     * Posts naming an image that is not committed, one per line.
     *
     * Checked here rather than left to the page, because an image that is not
     * there is a broken picture on the card, in the section and in whatever
     * A8.5's in-body card renders -- several broken things from one typo, none
     * of which fails anything.
     *
     * Body images are found by parsing, not by matching: `![alt](x.jpg)` in a
     * fenced code block is a line *about* markdown, and a post explaining how
     * to write one is exactly the post somebody will file. See
     * PostImages::inBody().
     *
     * Public and static for the reason `parse()` is: it is a judgement the
     * import makes, and testing it needs neither the table nor the command.
     *
     * @param array<string, array<string, mixed>> $posts
     * @return list<string>
     */
    public static function missingImages(array $posts): array
    {
        $missing = [];

        foreach ($posts as $slug => $post) {
            $wanted = [];

            if (($post['hero'] ?? null) !== null) {
                $wanted['hero'] = [(string) $post['hero']];
            }

            $body = PostImages::inBody((string) ($post['body'] ?? ''));

            if ($body !== []) {
                $wanted['image'] = array_values(array_unique($body));
            }

            foreach ($wanted as $kind => $files) {
                foreach ($files as $file) {
                    if (is_file(Helper::getPublicDir() . '/' . self::IMAGE_DIR . '/' . $file)) {
                        continue;
                    }

                    $missing[] = sprintf(
                        '%s.md names %s `%s`, which is not in %s.',
                        $slug,
                        $kind,
                        $file,
                        'public/' . self::IMAGE_DIR,
                    );
                }
            }
        }

        return $missing;
    }

    /**
     * A staged file's canonical name, or null where there is no hero.
     *
     * Read here rather than in `parse()`, which is pure and takes a string:
     * this needs the bytes on disk, and the bytes are what the hash is of.
     *
     * The file has to be there. `missingImages()` has already refused the run
     * if it is not, so reaching this with an unreadable file means something
     * removed it between the two, and a hero named after nothing is worse than
     * a failed import.
     */
    private static function canonicalHero(?string $hero): ?string
    {
        return $hero === null
            ? null
            : PostImageSet::canonical($hero, self::stagedContents($hero));
    }

    /**
     * How big each of one post's pictures is, keyed as the page will ask.
     *
     * A hero under its canonical hashed name, because that is what the row in
     * `posts` holds and so what the template looks up; a body image under the
     * name the author typed, because the markdown asks for it that way.
     *
     * `getimagesizefromstring()` reads a header and needs no `gd`, which
     * matters: an import that cannot resize must still record sizes, or a
     * machine without the extension would write rows the page then cannot use.
     *
     * @param array{hero: string|null, body: string, ...} $post
     * @return array<string, array{width: int, height: int}>
     */
    private static function imageSizes(array $post): array
    {
        $sizes = [];

        if ($post['hero'] !== null) {
            $contents = self::stagedContents($post['hero']);
            $sizes[PostImageSet::canonical($post['hero'], $contents)] = ImageResizer::dimensions($contents);
        }

        foreach (PostImages::inBody($post['body']) as $file) {
            $sizes[$file] = ImageResizer::dimensions(self::stagedContents($file));
        }

        $portrait = self::stagedAuthorPicture($post['author']);

        if ($portrait !== null) {
            $sizes[$portrait] = ImageResizer::dimensions(self::stagedContents($portrait));
        }

        return $sizes;
    }

    /**
     * Which of an author's candidate file names is actually staged, if any.
     *
     * Disk is the right question here and the wrong one in `PostImages`: at
     * import the files genuinely are on this machine, which is the one moment
     * anything can discover that a photograph was added. The page cannot,
     * which is the whole of A8.12.
     *
     * No picture is the ordinary case and not an error -- the page draws the
     * author's initials, which is a design rather than a gap.
     */
    private static function stagedAuthorPicture(string $author): ?string
    {
        foreach (PostImages::authorFiles($author) as $file) {
            if (is_file(Helper::getPublicDir() . '/' . self::IMAGE_DIR . '/' . $file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Every copy of every picture one post names, sent if it is not already up.
     *
     * The hero and the rest are not the same job. A hero is stored under a
     * hashed name in six sizes, because the markup picks a size; a body image
     * and an author's portrait are stored under the name they already have, at
     * one size, because the markup asks for them by that name.
     *
     * @param array{hero: string|null, body: string, ...} $post
     * @return list<string>
     */
    private static function uploadImages(PostImageUploader $uploader, array $post): array
    {
        $sent = [];

        if ($post['hero'] !== null) {
            $contents = self::stagedContents($post['hero']);
            $sent = $uploader->upload(PostImageSet::canonical($post['hero'], $contents), $contents);
        }

        $body = PostImages::inBody($post['body']);
        $portrait = self::stagedAuthorPicture($post['author']);

        foreach ($portrait === null ? $body : [...$body, $portrait] as $file) {
            $key = $uploader->uploadOne($file, self::stagedContents($file));

            if ($key !== null) {
                $sent[] = $key;
            }
        }

        return $sent;
    }

    /**
     * The bytes of a staged file.
     *
     * `missingImages()` has already refused the run if one is absent, so
     * reaching this with an unreadable file means something removed it between
     * the two checks -- and a post named after nothing is worse than a failed
     * import.
     */
    private static function stagedContents(string $file): string
    {
        $contents = @file_get_contents(Helper::getPublicDir() . '/' . self::IMAGE_DIR . '/' . $file);

        if ($contents === false) {
            throw new RuntimeException(sprintf('could not read `%s`', $file));
        }

        return $contents;
    }

    /**
     * Tags whose slug is shared by two different names, one per line.
     *
     * The slug comes from the name, so `Hand luggage` and `Hand Luggage` are
     * one tag with two names and the last file imported would decide what the
     * pill says -- a page changing because of the order `glob()` returned.
     * Refusing is the only answer that stays the same on every run.
     *
     * Public and static for the reason `parse()` is.
     *
     * @param array<string, array<string, mixed>> $posts
     * @return list<string>
     */
    public static function tagNameClashes(array $posts): array
    {
        $seen = [];
        $clashes = [];

        foreach ($posts as $slug => $post) {
            foreach ($post['tags'] ?? [] as $tag => $name) {
                if (isset($seen[$tag]) && $seen[$tag]['name'] !== $name) {
                    $clashes[] = sprintf(
                        'tag `%s` is written `%s` in %s.md and `%s` in %s.md -- pick one.',
                        $tag,
                        $seen[$tag]['name'],
                        $seen[$tag]['slug'],
                        (string) $name,
                        $slug,
                    );

                    continue;
                }

                $seen[$tag] = ['name' => $name, 'slug' => $slug];
            }
        }

        return $clashes;
    }

    /**
     * One post file, split into its header and its prose.
     *
     * Every refusal is a way a broken file becomes a broken page rather than
     * an error. The lengths are checked here and not left to the columns:
     * with STRICT_TRANS_TABLES an over-long summary is a SQL error naming a
     * column, where this names the file and says what to do about it.
     *
     * @return array{title: string, published_at: string, author: string, summary: string, hero: ?string, hero_alt: ?string, tags: array<string, string>, body: string}
     */
    public static function parse(string $contents): array
    {
        ['fields' => $fields, 'body' => $body] = self::header($contents, self::REQUIRED, self::OPTIONAL);

        $hero = $fields['hero'] ?? null;
        $alt = $fields['hero_alt'] ?? null;

        // Alt text is content, not decoration: it is what a reader who cannot
        // see the image receives instead. A hero is never decorative, so one
        // without alt text is an accessibility defect that would ship in
        // silence -- nothing renders differently and nothing fails.
        if ($hero !== null && $alt === null) {
            throw new RuntimeException('a post with a `hero` must say what it shows in `hero_alt`');
        }

        if ($hero === null && $alt !== null) {
            throw new RuntimeException('`hero_alt` describes nothing: there is no `hero`');
        }

        // The page already has an `h1`: the post's title, in the band. A
        // second one in the body is two documents on one page as far as a
        // screen reader's heading list is concerned, and nothing looks wrong.
        //
        // The `NormalizeHeadings` extension was going to do this, until it was
        // measured: it leaves a body `#` as an `h1`, so it would have looked
        // like a guard and been none.
        if (preg_match('/^# /m', $body) === 1) {
            throw new RuntimeException(
                'the body opens a top-level heading with `#`, and the page already has one'
                . ' -- start the prose at `##`',
            );
        }

        self::within('title', $fields['title'], self::TITLE_LIMIT);
        self::within('summary', $fields['summary'], self::SUMMARY_LIMIT);

        if ($alt !== null) {
            self::within('hero_alt', $alt, self::ALT_LIMIT);
        }

        return [
            'title' => $fields['title'],
            'published_at' => self::published($fields['published']),
            'author' => $fields['author'],
            'summary' => $fields['summary'],
            'hero' => $hero,
            'hero_alt' => $alt,
            'tags' => self::tags($fields['tags'] ?? ''),
            'body' => $body,
        ];
    }

    /**
     * `tags: Security, Packing` as slug => name.
     *
     * The author writes the name and the slug is derived from it, rather than
     * the other way round: a name is the thing that appears on the page and in
     * the pill, and asking somebody to keep a slug and a name in step by hand
     * is asking for them to drift.
     *
     * @return array<string, string>
     */
    private static function tags(string $value): array
    {
        $tags = [];

        foreach (explode(',', $value) as $written) {
            $name = trim($written);

            if ($name === '') {
                continue;
            }

            $slug = self::slugify($name);

            if ($slug === '') {
                throw new RuntimeException(sprintf(
                    'tag `%s` has no letters or digits in it, so it cannot be a URL',
                    $name,
                ));
            }

            if (mb_strlen($name) > self::TAG_LIMIT) {
                throw new RuntimeException(sprintf(
                    'tag `%s` is %d characters and the column holds %d',
                    $name,
                    mb_strlen($name),
                    self::TAG_LIMIT,
                ));
            }

            $tags[$slug] = $name;
        }

        return $tags;
    }

    /**
     * A name reduced to something a URL can hold.
     *
     * Kept in step with the route pattern and with the slug rule `read()`
     * applies to a file name, because a tag nothing can route to is a pill
     * that 404s.
     */
    private static function slugify(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));

        return trim((string) $slug, '-');
    }

    /**
     * `published` as a datetime the column will take, or a refusal.
     *
     * Written as a date, stored as a datetime, because the ordering wants a
     * tiebreaker finer than a day and an author has no reason to type a
     * time.
     *
     * Not `strtotime`, and measured rather than assumed: it reads `2026-02-30`
     * as 2 March rather than refusing it, `March 2026` as the first of that
     * month, and `next tuesday` as whichever day the import happens to run
     * near. Every one of those turns a typo into a real, plausible, wrong date
     * that nothing mentions -- and the section is ordered by this column, so a
     * wrong date moves a post to the top. One shape, and `checkdate` to ask
     * whether the day exists.
     */
    private static function published(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) !== 1) {
            throw new RuntimeException(sprintf('published must be written YYYY-MM-DD, not `%s`', $value));
        }

        [, $year, $month, $day] = $parts;

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new RuntimeException(sprintf('published names a day that does not exist: `%s`', $value));
        }

        return $value . ' 00:00:00';
    }

    /**
     * A header value the column can actually hold.
     */
    private static function within(string $key, string $value, int $limit): void
    {
        if (mb_strlen($value) > $limit) {
            throw new RuntimeException(sprintf(
                '%s is %d characters and the column holds %d',
                $key,
                mb_strlen($value),
                $limit,
            ));
        }
    }

    /**
     * The fenced header and the prose under it.
     *
     * The same reader `articles:import` uses, and the same refusals: an
     * unknown key is a typo that would otherwise be silently dropped, which is
     * how `publised: 2026-01-01` ends up as a missing-header error somewhere
     * far from the file that caused it.
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
