<?php

declare(strict_types=1);

namespace TripBuilder\View;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
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

            // `class` and nothing else. With no allow-list the Attributes
            // extension passes almost anything through: `onclick` it strips on
            // its own, but `{style="position:fixed;top:0"}` survives, which is
            // an author covering the page with one line of markdown. `id` is
            // left out too -- a body writing `{#main}` would take the id the
            // skip link lands on.
            'attributes' => ['allow' => ['class']],

            // `rel` and nothing else. A new window is a decision to make per
            // link and not for every outbound link on the site, and a class
            // would style them differently for no stated reason. Relative,
            // anchor and mailto links are left alone with no host configured
            // -- checked, rather than assumed from the option's name.
            'external_link' => [
                'open_in_new_window' => false,
                'html_class' => '',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        // Four additions, and each one is either opt-in syntax or a safety
        // header, so nothing already written renders differently: there is no
        // `{...}` anywhere in the README or the help bodies today.
        //
        // Attributes is the load-bearing one. A body cannot contain a `<div>`
        // -- `html_input => 'escape'` is what makes it safe to print operator
        // text at all -- so `{.callout}` on its own line above a blockquote is
        // the only way to mark one up without reopening that.
        $environment->addExtension(new AttributesExtension());

        // Travel facts date. A footnote is where "as of 2026" belongs, rather
        // than in a sentence that has to be rewritten every year.
        $environment->addExtension(new FootnoteExtension());

        // For "what you need" lists, which are a term and its explanation
        // rather than bullets.
        $environment->addExtension(new DescriptionListExtension());

        // `rel="noopener noreferrer"` on outbound links, which the prose here
        // could not add for itself.
        $environment->addExtension(new ExternalLinkExtension());

        return (string) new MarkdownConverter($environment)->convert($markdown);
    }
}
