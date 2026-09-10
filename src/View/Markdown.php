<?php

declare(strict_types=1);

namespace TripBuilder\View;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown to HTML, configured once.
 *
 * Two callers now -- the README behind /about and the help articles -- and the
 * configuration is shared rather than copied because one of the two settings is
 * a safety setting. `html_input => 'escape'` is what makes it safe to print the
 * result without escaping it again; a second copy that lost the line would
 * hand raw HTML from a database row straight to the page, and the `|raw` at the
 * other end would not know the difference.
 *
 * What the settings buy:
 *
 *   - `html_input => 'escape'` -- raw HTML in the source is escaped, not
 *     passed through and not stripped. A `<script>` in an article body reaches
 *     the reader as visible text.
 *   - `allow_unsafe_links => false` -- `javascript:`, `data:` and `vbscript:`
 *     schemes are dropped from links and images.
 *
 * Together they are the whole of the defence, and they are why this app can
 * print converter output with `|raw` when it has never printed operator-typed
 * text that way. Nothing here sanitises after the fact: there is no HTML
 * purifier in this project and this is not the place to introduce one.
 *
 * GitHub-flavoured markdown comes along for tables and autolinks, which a
 * README written on GitHub will use. An article needs neither, and gets them
 * anyway -- one configuration that both callers trust is worth more than a
 * narrower one each.
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $environment = new Environment([
            // Both files reaching this are version-controlled rather than
            // submitted, but escaping is still the right default: it means a
            // stray tag renders as text instead of becoming markup in the
            // page. It stops being merely tidy the moment an editor writes to
            // the articles table, which is the direction this is heading.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return (string) new MarkdownConverter($environment)->convert($markdown);
    }
}
