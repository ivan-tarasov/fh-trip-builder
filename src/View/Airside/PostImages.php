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
use TripBuilder\Helper;

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
     */
    public const string DIRECTORY = 'frontend/img/airside';

    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Above the core renderers, which are registered at 0.
        $environment->addRenderer(Image::class, new PostImageRenderer(), 10);
        $environment->addRenderer(Paragraph::class, new PostFigureRenderer(), 10);
    }

    /**
     * The public path for a file name, as the page will ask for it.
     */
    public static function url(string $file): string
    {
        return '/' . self::DIRECTORY . '/' . $file;
    }

    /**
     * Width and height as the file actually has them, or null.
     *
     * Null rather than a guess: attributes that disagree with the file are
     * worse than none, because the browser reserves the wrong box and the page
     * jumps anyway -- only later, and by a different amount.
     *
     * @return array{int, int}|null
     */
    public static function dimensions(string $file): ?array
    {
        $path = Helper::getRootDir() . '/' . self::DIRECTORY . '/' . $file;
        $size = @getimagesize($path);

        return $size === false ? null : [(int) $size[0], (int) $size[1]];
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
