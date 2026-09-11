<?php

declare(strict_types=1);

namespace TripBuilder\View\Airside;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Puts one related-post card in the middle of a body.
 *
 * The card is not written by the author and does not appear in the file, which
 * is the whole point: it names whichever post currently shares a tag, so a post
 * written in June can start pointing at one written in September without being
 * touched.
 *
 * Where it goes: **before the second `h2`**. The first section is the one that
 * decides whether a reader stays, so interrupting it is the one place a card
 * costs more than it offers -- and a post with fewer than two `h2`s has no
 * middle to interrupt, so it gets nothing.
 */
final readonly class PostRelatedCard implements ExtensionInterface
{
    /**
     * @param array{slug: string, title: string, summary: string, hero: ?string, hero_alt: ?string} $post
     */
    public function __construct(private array $post) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(RelatedCard::class, new RelatedCardRenderer());

        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            $second = self::secondSection($event);

            if ($second === null) {
                return;
            }

            $second->insertBefore(new RelatedCard(
                $this->post['slug'],
                $this->post['title'],
                $this->post['summary'],
                $this->post['hero'],
                $this->post['hero_alt'],
            ));
        });
    }

    /**
     * The second top-level `h2`, or null where there is not one.
     *
     * Top-level specifically: a heading inside a blockquote or a list item is
     * part of something else, and inserting a card as its sibling would put the
     * card inside that thing.
     */
    private static function secondSection(DocumentParsedEvent $event): ?Heading
    {
        $seen = 0;

        foreach ($event->getDocument()->children() as $node) {
            if (!$node instanceof Heading || $node->getLevel() !== 2) {
                continue;
            }

            if (++$seen === 2) {
                return $node;
            }
        }

        return null;
    }
}
