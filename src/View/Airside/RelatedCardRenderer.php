<?php

declare(strict_types=1);

namespace TripBuilder\View\Airside;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use Stringable;

/**
 * The card's markup.
 *
 * An `<aside>`, because it is beside the argument rather than part of it: a
 * reader who skips it loses nothing the post was saying. That also keeps it
 * out of the document outline, which matters here -- a heading inside it would
 * appear in the table of contents `[TOC]` builds, between two sections of the
 * post, describing a different post entirely.
 */
final class RelatedCardRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
    {
        RelatedCard::assertInstanceOf($node);
        \assert($node instanceof RelatedCard);

        // The label and the title stack, and the image stands beside the pair.
        // Without this wrapper all three are flex children of the link and sit
        // in one row, which reads well enough with no image and falls apart
        // with one.
        $body = [
            new HtmlElement('div', ['class' => 'airside-inline__text'], [
                new HtmlElement('p', ['class' => 'airside-inline__label'], 'More on this'),
                new HtmlElement('p', ['class' => 'airside-inline__title'], $node->title),
            ]),
        ];

        if ($node->hero !== null) {
            array_unshift($body, new HtmlElement('img', [
                'class' => 'airside-inline__image',
                'src' => PostImages::url($node->hero),
                // The post's own alt text, written in its file and required
                // there whenever a hero is named.
                'alt' => $node->heroAlt ?? '',
                'loading' => 'lazy',
            ], '', true));
        }

        return new HtmlElement('aside', ['class' => 'airside-inline'], [
            new HtmlElement('a', [
                'class' => 'airside-inline__link',
                'href' => '/airside/' . $node->slug,
            ], $body),
        ]);
    }
}
