<?php

declare(strict_types=1);

namespace TripBuilder\View\Airside;

use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use Stringable;

/**
 * A paragraph, or the absence of one around a figure.
 *
 * `<figure>` is a block element and `<p>` cannot contain one. A browser given
 * `<p><figure>…</figure></p>` repairs it by closing the paragraph early,
 * moving the figure out and leaving an empty `<p>` behind -- so the markup has
 * to be right here rather than left to the parser's recovery.
 *
 * Everything that is not a lone image renders exactly as the core does.
 */
final class PostFigureRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable|string
    {
        Paragraph::assertInstanceOf($node);
        \assert($node instanceof Paragraph);

        if (PostImages::isFigure($node)) {
            // The image renderer draws the whole figure; this only declines to
            // wrap it.
            return $childRenderer->renderNodes($node->children());
        }

        return new HtmlElement('p', $node->data->get('attributes'), $childRenderer->renderNodes($node->children()));
    }
}
