<?php

declare(strict_types=1);

namespace TripBuilder\View\Airside;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A card naming another post, standing in the middle of this one.
 *
 * A node rather than a string of HTML spliced into the output. The funnel is
 * explicit about why and it is right: "before the second `<h2>`" as a regular
 * expression breaks the first time a heading contains a link or a `<code>`
 * span, and these bodies are operator-written so it will. Inserting a node
 * into the parsed document cannot be wrong about where a heading ends.
 *
 * It carries what the card draws and nothing else. The post it names was
 * chosen by the controller, because choosing is a database question and this
 * layer has no connection.
 */
final class RelatedCard extends AbstractBlock
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $hero,
        public readonly ?string $heroAlt,
    ) {
        parent::__construct();
    }
}
