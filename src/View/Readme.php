<?php

declare(strict_types=1);

namespace TripBuilder\View;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Throwable;
use TripBuilder\Helper;

/**
 * The README, rendered for the /about page.
 *
 * The point is that there is one copy of this text. The README is what a
 * developer reads on GitHub and what a visitor reads on the site, so the two
 * cannot describe different projects -- editing the file changes the page.
 *
 * Only part of the file is wanted. A README ends in `git clone` and a CLI
 * command reference, which is for somebody with a terminal; the marker comments
 * pick out the half that describes the product. They are HTML comments, so they
 * do not show on GitHub, and moving them is how the page's extent is changed.
 */
final class Readme
{
    private const string FILE = 'README.md';

    private const string START = '<!-- about:start -->';
    private const string END = '<!-- about:end -->';

    private const string CACHE_DIR = 'cache/readme';

    /**
     * Reference link definitions -- `[label]: https://…` on its own line.
     *
     * These sit at the very bottom of a README, below the end marker, while the
     * links and images that use them are above it. Slicing without them would
     * leave every badge and link in the kept half unresolved, so they are
     * carried across whatever the markers say. Definitions nothing references
     * render as nothing, so carrying all of them costs only parse time.
     */
    private const string DEFINITION = '/^\[[^\]]+\]:\s*\S+.*$/m';

    /**
     * An HTML comment, including the marker comments themselves.
     *
     * Comments are notes to whoever edits the file, never content. They have to
     * be removed rather than left to the converter: raw HTML is escaped here on
     * purpose, so a comment left in would be printed to the page as text
     * instead of disappearing the way it does on GitHub.
     */
    private const string COMMENT = '/<!--.*?-->/s';

    /**
     * The rendered HTML, or null when the README cannot be read.
     *
     * Already-safe HTML: raw HTML in the source is escaped rather than passed
     * through, and unsafe link schemes are dropped, so the template prints this
     * without escaping it again.
     */
    public function html(): ?string
    {
        $path = $this->path();
        $stamp = $this->updatedAt();

        if ($stamp === null) {
            return null;
        }

        $cached = $this->cached($stamp);

        if ($cached !== null) {
            return $cached;
        }

        $markdown = @file_get_contents($path);

        if ($markdown === false) {
            return null;
        }

        $html = $this->convert($this->slice($markdown));

        $this->store($stamp, $html);

        return $html;
    }

    /**
     * When the README last changed, so the page can say how current it is.
     */
    public function updatedAt(): ?int
    {
        $stamp = @filemtime($this->path());

        return $stamp === false ? null : $stamp;
    }

    /**
     * The part of the file the markers select, plus every link definition.
     *
     * A missing marker is not an error: without them the whole README renders,
     * which is the reasonable reading of "no bounds given".
     */
    private function slice(string $markdown): string
    {
        $body = $markdown;

        $start = strpos($body, self::START);

        if ($start !== false) {
            $body = substr($body, $start + strlen(self::START));
        }

        $end = strpos($body, self::END);

        if ($end !== false) {
            $body = substr($body, 0, $end);
        }

        // Nothing between the markers: fall back to the whole file rather than
        // rendering a blank page.
        if (trim($body) === '') {
            $body = $markdown;
        }

        // After slicing, so the markers have already done their job.
        $body = (string) preg_replace(self::COMMENT, '', $body);

        preg_match_all(self::DEFINITION, $markdown, $matches);

        return trim($body) . "\n\n" . implode("\n", $matches[0]) . "\n";
    }

    private function convert(string $markdown): string
    {
        $environment = new Environment([
            // The file is version-controlled rather than submitted, but escaping
            // is still the right default: it means a stray tag renders as text
            // instead of becoming markup in the page.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        // Tables and autolinks, which a README written on GitHub will use.
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return (string) new MarkdownConverter($environment)->convert($markdown);
    }

    /**
     * Rendered HTML for this version of the file, or null when not cached.
     *
     * Converting the README costs about 10ms, which is worth avoiding on a page
     * whose content only changes when the file does. The modification time is
     * the cache key, so an edit invalidates it with no cache to clear.
     */
    private function cached(int $stamp): ?string
    {
        $html = @file_get_contents($this->cachePath($stamp));

        return $html === false ? null : $html;
    }

    private function store(int $stamp, string $html): void
    {
        $directory = Helper::getRootDir() . '/' . self::CACHE_DIR;

        try {
            if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
                return;
            }

            // Older renders are for versions of the file that no longer exist.
            foreach (glob($directory . '/*.html') ?: [] as $stale) {
                @unlink($stale);
            }

            @file_put_contents($this->cachePath($stamp), $html);
        } catch (Throwable) {
            // A page that renders is worth more than a warm cache; the next
            // request simply converts again.
        }
    }

    private function cachePath(int $stamp): string
    {
        return sprintf('%s/%s/%d.html', Helper::getRootDir(), self::CACHE_DIR, $stamp);
    }

    private function path(): string
    {
        return Helper::getRootDir() . '/' . self::FILE;
    }
}
