<?php

declare(strict_types=1);

namespace TripBuilder\View\Airside;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use Stringable;

/**
 * One image in a post body.
 *
 * The core renderer emits `src`, `alt` and `title` and stops. This one
 * resolves the file name, measures the file, and turns a `title` into a
 * caption where the image stands alone -- see PostImages for why each.
 */
final class PostImageRenderer implements NodeRendererInterface
{
    /**
     * @param array<string, array{0: int, 1: int}> $dimensions
     *     how big each file is, recorded at import because the file itself is
     *     no longer here to ask -- see Repository\PostImageRepository
     */
    public function __construct(private readonly array $dimensions = []) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
    {
        Image::assertInstanceOf($node);
        \assert($node instanceof Image);

        $file = $node->getUrl();

        $attributes = [
            'src' => PostImages::url($file),
            'alt' => $this->altText($node, $childRenderer),
            // Below the fold by definition: the hero is above every one of
            // these, so none of them is what a reader is waiting for.
            'loading' => 'lazy',
        ];

        // Absent rather than guessed. Attributes that disagree with the file
        // are worse than none: the browser reserves the wrong box and the page
        // jumps anyway, only later and by a different amount.
        $dimensions = $this->dimensions[$file] ?? null;

        if ($dimensions !== null) {
            // Strings: HtmlElement escapes every attribute value and its
            // escaper only takes strings, so an int is a TypeError at render.
            $attributes['width'] = (string) $dimensions[0];
            $attributes['height'] = (string) $dimensions[1];
        }

        $image = new HtmlElement('img', $attributes, '', true);
        $parent = $node->parent();

        if (!$parent instanceof Paragraph || !PostImages::isFigure($parent)) {
            return $image;
        }

        $caption = $node->getTitle();

        if ($caption === null || $caption === '') {
            return new HtmlElement('figure', [], $image);
        }

        return new HtmlElement('figure', [], [
            $image,
            new HtmlElement('figcaption', [], $caption),
        ]);
    }

    /**
     * The alt text, which is whatever is written between the brackets.
     *
     * Rendered as plain text rather than as markup: `![**a wing**](x.jpg)`
     * would otherwise put `<strong>` inside an attribute, where it is printed
     * rather than applied.
     */
    private function altText(Image $node, ChildNodeRendererInterface $childRenderer): string
    {
        return strip_tags((string) $childRenderer->renderNodes($node->children()));
    }
}
