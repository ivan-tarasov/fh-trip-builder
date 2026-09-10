<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\View\SuggestedOrigin;

/**
 * Which place the homepage opens its "From" field on.
 *
 * The rule is an order, and the order is the decision: a search somebody ran
 * is a statement they made, where a location lookup is a guess about them. So
 * the statement wins. Somebody who lives in Montreal and books from Toronto
 * has said which they want, and a guess that overruled them every visit would
 * be worse than an empty field.
 */
final class SuggestedOriginTest extends TestCase
{
    /** @var list<string> */
    private const array OFFERED = ['YUL', 'YYZ', 'LON', 'NYC'];

    public function testTheLastSearchWinsOverWhereTheyAppearToBe(): void
    {
        self::assertSame('YYZ', SuggestedOrigin::choose('YYZ', 'YUL', self::OFFERED));
    }

    public function testWhereTheyAreIsTheColdStart(): void
    {
        self::assertSame('YUL', SuggestedOrigin::choose(null, 'YUL', self::OFFERED));
    }

    public function testNeitherLeavesTheFieldAlone(): void
    {
        self::assertNull(SuggestedOrigin::choose(null, null, self::OFFERED));
    }

    /**
     * A code the picker cannot select is no answer.
     *
     * Both candidates come from outside: one from a cookie, one from a header
     * a proxy sets. Neither is checked anywhere else, and a code with no option
     * behind it would render as an empty field -- so this refuses on purpose
     * rather than by accident, and falls through to the next candidate.
     */
    public function testACodeThePickerDoesNotOfferIsSkipped(): void
    {
        self::assertNull(SuggestedOrigin::choose('ZZZ', null, self::OFFERED));
        self::assertSame('YUL', SuggestedOrigin::choose('ZZZ', 'YUL', self::OFFERED));
        self::assertNull(SuggestedOrigin::choose('ZZZ', 'QQQ', self::OFFERED));
    }

    public function testAnEmptyStringIsNotAPlace(): void
    {
        self::assertSame('YUL', SuggestedOrigin::choose('', 'YUL', self::OFFERED));
        self::assertNull(SuggestedOrigin::choose('', '', self::OFFERED));
    }

    /**
     * Nothing is offered, so nothing can be chosen -- the state a fresh
     * install is in before `flights:add` has run.
     */
    public function testWithNothingOfferedThereIsNothingToChoose(): void
    {
        self::assertNull(SuggestedOrigin::choose('YUL', 'YYZ', []));
    }
}
