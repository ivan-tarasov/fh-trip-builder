<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * How helpful an article is, from a handful of votes.
 *
 * The obvious answer -- the share of readers who said yes -- ranks one vote out
 * of one above forty-seven out of fifty, because both are asked to prove
 * themselves with a single number and the smaller sample has nothing pulling it
 * down. On five articles that have just been given a thumbs-up button, almost
 * every article is the small sample, so the obvious answer is wrong nearly all
 * of the time it is used.
 *
 * So this returns the low end of a confidence interval instead: roughly, the
 * share we would still believe if we were being pessimistic about how few
 * people have voted. One out of one scores 0.21 and forty-seven out of fifty
 * scores 0.84. The ordering is the point -- the figure itself is never shown to
 * anybody, it only sorts.
 *
 * Pure and static, with no database and no config, so the arithmetic can be
 * tested on its own the way Money's is.
 */
final class ArticleRating
{
    /**
     * Below this, a tally is noise rather than a finding.
     *
     * "100% helpful (1 vote)" reads as a verdict and is really one person, so
     * the sheet thanks the reader and shows no figures until there are enough
     * of them to mean something.
     */
    public const int SHOW_FROM = 5;

    /**
     * 1.96, which is the 95% two-tailed normal quantile.
     *
     * Written out rather than derived: there is no statistics function in core
     * PHP to derive it from, and this is the value every published form of this
     * score uses.
     */
    private const float Z = 1.96;

    /**
     * A sortable score in 0..1. More votes at the same share score higher.
     */
    public static function score(int $helpful, int $votes): float
    {
        if ($votes < 1) {
            return 0.0;
        }

        // Clamped because the square root below is of p(1-p), which goes
        // negative if a caller ever passes more yeses than votes. SQL cannot
        // produce that -- SUM(helpful) over COUNT(*) -- but a NaN score would
        // sort unpredictably rather than fail, so it is not left to trust.
        $helpful = max(0, min($helpful, $votes));

        // Nobody found it helpful, which is nought exactly. Returned here
        // rather than calculated, because with no yeses the two halves of the
        // interval below are both z^2/2n and cancel to zero algebraically --
        // but in floating point they cancel to about 1e-17, with either sign
        // depending on the number of votes, so neither a comparison nor a
        // clamp can be relied on to tidy it away. A score of -1e-17 sorts an
        // article somebody read and disliked below one nobody has voted on.
        if ($helpful === 0) {
            return 0.0;
        }

        $share = $helpful / $votes;

        $centre = $share + self::Z ** 2 / (2 * $votes);
        $spread = self::Z * sqrt(($share * (1 - $share) + self::Z ** 2 / (4 * $votes)) / $votes);
        $score = ($centre - $spread) / (1 + self::Z ** 2 / $votes);

        // Held inside 0..1 so the return type means what it says. The zero
        // case above is the one that actually escaped the range; this is here
        // so a future change to the arithmetic cannot quietly reintroduce a
        // score that sorts outside the set it is ranking.
        return min(1.0, max(0.0, $score));
    }

    /**
     * Whether there are enough votes to put a number in front of a reader.
     */
    public static function worthShowing(int $votes): bool
    {
        return $votes >= self::SHOW_FROM;
    }
}
