<?php

declare(strict_types=1);

namespace TripBuilder\View\Airside;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Parser\MarkdownParser;
use TripBuilder\Cdn;
use TripBuilder\Config;

/**
 * Images inside an Airside post body.
 *
 * Registered only on the converter posts use, not on the shared one: `/about`
 * and `/help` go through `View\Markdown::toHtml()` and neither writes an image
 * into its prose, so giving all three a renderer that resolves paths against
 * an Airside directory would be a rule for one family applied to three.
 *
 * Three things it does, and the reason for each:
 *
 *   - **A file name becomes a path.** An author writes `![alt](wing.jpg)`, the
 *     same way `hero:` takes a file name, so moving the directory later is a
 *     change to this class rather than to every body.
 *   - **Dimensions are read off the file.** Without `width` and `height` the
 *     page reflows as each image loads, which is the thing "responsive sizes"
 *     in the plan was actually about. `getimagesize()` reads the header only
 *     and needs no GD -- which matters, because CI installs `mysqli`,
 *     `pdo_mysql`, `curl` and `mbstring`, and this way it makes no difference
 *     whether `gd` came with them.
 *   - **An image alone in a paragraph becomes a `<figure>`.** With a markdown
 *     title it gains a `<figcaption>`: a caption somebody can read, rather
 *     than a `title` tooltip that never appears on a touchscreen.
 */
final readonly class PostImages implements ExtensionInterface
{
    /**
     * Where a body's images live, and where `hero:` images already live.
     *
     * One constant rather than two because they are one directory; if A8.2's
     * successor moves them, both move together.
     *
     * Relative to the document root, because it is both halves of the same
     * thing: the URL a page asks for and, under `Helper::getPublicDir()`, the
     * directory the importer reads.
     */
    public const string DIRECTORY = 'img/airside';

    /**
     * @param array<string, array{0: int, 1: int}> $dimensions
     *     how big each file is, keyed by the name the markdown asks for
     */
    public function __construct(private array $dimensions = []) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Above the core renderers, which are registered at 0.
        $environment->addRenderer(Image::class, new PostImageRenderer($this->dimensions), 10);
        $environment->addRenderer(Paragraph::class, new PostFigureRenderer(), 10);
    }

    /**
     * Where the page asks for one of these files.
     *
     * The distribution when there is one, and the staging directory when there
     * is not. Those are not two ways of saying the same thing: the bucket holds
     * the sized copies and the staging directory holds the original somebody
     * dropped there before importing. So locally a page shows the full-size
     * file and in production it shows the right one, which is the correct
     * behaviour in both places and not a fallback that pretends otherwise.
     *
     * `Cdn::isConfigured()` first, because `getUrl()` with no host returns
     * `///images/...` -- a URL that reads as a path on the current host and
     * breaks without saying so.
     */
    public static function url(string $file): string
    {
        return Cdn::isConfigured()
            // A default, because this is reached from a unit test with no
            // Config booted, and without one the key silently loses its
            // prefix -- `//host/airside/x.jpg` rather than
            // `//host/images/airside/x.jpg`, which 404s rather than erroring.
            ? Cdn::getUrl(Config::get('site.static.endpoint.images', 'images') . '/airside/' . $file)
            : '/' . self::DIRECTORY . '/' . $file;
    }

    /**
     * The names an author's picture could have, in the order they are tried.
     *
     * Derived from the name rather than stored, because `posts.author` is the
     * only record of who wrote a post and a second column naming a file would
     * be a second thing to keep in step. `Ivan Tarasov` gives
     * `authors/ivan-tarasov.jpg` and its siblings.
     *
     * Spelled here and used twice: the importer asks disk which of these is
     * there, and `author()` asks the table. Two spellings of the same three
     * names would eventually disagree about an extension, and the symptom
     * would be a portrait that uploads and never appears.
     *
     * @return list<string>
     */
    public static function authorFiles(string $name): array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');

        if ($slug === '') {
            return [];
        }

        return array_map(
            static fn(string $extension): string => 'authors/' . $slug . '.' . $extension,
            ['jpg', 'png', 'webp'],
        );
    }

    /**
     * Where an author's picture lives, if they have one.
     *
     * `$known` and not `is_file()`. It used to look on disk, which worked until
     * A8.6 sent these to a bucket and stopped committing the directory -- after
     * which every deployed page drew initials with the photograph sitting in
     * the distribution, and looked finished while doing it.
     *
     * Null is still a real answer and not a failure: nothing here ships an
     * invented portrait, so the page draws initials instead. What changed is
     * that a photograph added later needs an import to be seen, the way every
     * other image already does.
     *
     * @param array<string, mixed> $known every file the section has a row for
     */
    public static function author(string $name, array $known = []): ?string
    {
        foreach (self::authorFiles($name) as $file) {
            if (array_key_exists($file, $known)) {
                return self::url($file);
            }
        }

        return null;
    }

    /**
     * The letters to draw when there is no picture.
     *
     * First letter of the first and last words, which is what a reader
     * recognises; one letter where there is only one word.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $words = array_values(array_filter($words));

        if ($words === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr($words[0], 0, 1));

        return count($words) === 1
            ? $first
            : $first . mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1));
    }

    /**
     * Every image a body asks for, in the order it asks.
     *
     * Parsed rather than matched with a regular expression, because
     * `![alt](x.jpg)` inside a fenced code block is a line about markdown and
     * not a request for a file -- and a body explaining how to write one is
     * exactly the post somebody will file. The parser already knows the
     * difference; a pattern would have to be taught it.
     *
     * @return list<string>
     */
    public static function inBody(string $markdown): array
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());

        $found = [];

        foreach (new MarkdownParser($environment)->parse($markdown)->iterator() as $node) {
            if ($node instanceof Image) {
                $found[] = $node->getUrl();
            }
        }

        return $found;
    }

    /**
     * Whether a paragraph holds one image and nothing else.
     *
     * What separates a figure from a sentence with a picture in it. Shared by
     * the two renderers so they cannot disagree -- if they did, the page would
     * get a `<figure>` inside a `<p>`, which is invalid and which browsers
     * repair by moving the figure out and leaving an empty paragraph behind.
     */
    public static function isFigure(Paragraph $paragraph): bool
    {
        $children = $paragraph->children();

        return count($children) === 1 && $children[0] instanceof Image;
    }
}
