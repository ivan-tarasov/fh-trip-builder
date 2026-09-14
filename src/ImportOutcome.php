<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * What happened to one row when the importer reached it.
 *
 * Three answers and not two, because A3.4 (#102) gave the files a third
 * possibility: a row somebody has edited in the panel is left exactly as it is.
 * The importer has to be able to say that -- a run reporting "unchanged" for a
 * row it deliberately skipped would be telling the truth about the row and
 * lying about the reason, and the reason is the whole of this feature.
 *
 * An enum rather than a bool and a bool, so that adding the third case made
 * every caller fail to compile rather than quietly fall into the wrong branch.
 */
enum ImportOutcome
{
    /** The file said something new, and the row now says it. */
    case Written;

    /** The file and the row already agreed. */
    case Unchanged;

    /** A person owns this row. The file was read and not applied. */
    case Kept;
}
