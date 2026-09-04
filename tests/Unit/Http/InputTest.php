<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TripBuilder\Http\Input;

final class InputTest extends TestCase
{
    public function testStrTrimsAndFallsBackToTheDefault(): void
    {
        $input = new Input(['from' => '  LON  ', 'blank' => '']);

        self::assertSame('LON', $input->str('from'));
        self::assertSame('', $input->str('blank'));
        self::assertSame('', $input->str('missing'));
        self::assertSame('fallback', $input->str('missing', 'fallback'));
    }

    public function testStrSurvivesAnArrayWhereAStringWasExpected(): void
    {
        // `?from[]=LON` makes the value an array. A bare (string) cast throws;
        // this is the shape a crafted URL reaches for first.
        $input = new Input(['from' => ['LON', 'PAR']]);

        self::assertSame('', $input->str('from'));
        self::assertSame('none', $input->str('from', 'none'));
        self::assertNull($input->nullableStr('from'));
    }

    public function testNullableStrKeepsAbsenceDistinctFromEmpty(): void
    {
        $input = new Input(['class' => '', 'other' => 'business']);

        // An empty cabin and no cabin are different questions, and the enum
        // reading them answers each differently.
        self::assertSame('', $input->nullableStr('class'));
        self::assertNull($input->nullableStr('missing'));
        self::assertSame('business', $input->nullableStr('other'));
    }

    public function testIntRejectsWhatACastWouldHaveAccepted(): void
    {
        $input = new Input(['n' => '42', 'messy' => '10abc', 'blank' => '', 'float' => '1.5']);

        self::assertSame(42, $input->int('n'));
        // (int) would have made these 10, 0 and 1.
        self::assertSame(0, $input->int('messy'));
        self::assertSame(0, $input->int('blank'));
        self::assertSame(0, $input->int('float'));
        self::assertSame(7, $input->int('missing', 7));
    }

    public function testIntWithinReturnsTheDefaultOutsideTheRange(): void
    {
        $input = new Input(['shown' => '40', 'huge' => '999999', 'low' => '-3']);

        self::assertSame(40, $input->intWithin('shown', 10, 10, 210));
        // Not clamped to the bound: a request for a hundred thousand rows is
        // answered with the default rather than the maximum.
        self::assertSame(10, $input->intWithin('huge', 10, 10, 210));
        self::assertSame(10, $input->intWithin('low', 10, 10, 210));
    }

    public function testIdsParsesAnOrderedList(): void
    {
        $input = new Input(['itin' => '12,7,3']);

        self::assertSame([12, 7, 3], $input->ids('itin'));
    }

    public function testIdsRejectsTheWholeListWhenAnyPartIsBad(): void
    {
        // The important one. Dropping the bad element instead would hand back a
        // shorter itinerary that still resolves -- a different trip from the
        // one the link named, priced and booked without anyone noticing.
        self::assertSame([], new Input(['itin' => '12,abc,3'])->ids('itin'));
        self::assertSame([], new Input(['itin' => '12,0,3'])->ids('itin'));
        self::assertSame([], new Input(['itin' => '12,-4,3'])->ids('itin'));
        self::assertSame([], new Input(['itin' => '12,,3'])->ids('itin'));
    }

    public function testIdsIsEmptyWhenThereIsNothingToParse(): void
    {
        self::assertSame([], new Input()->ids('itin'));
        self::assertSame([], new Input(['itin' => ''])->ids('itin'));
        self::assertSame([], new Input(['itin' => '   '])->ids('itin'));
        self::assertSame([], new Input(['itin' => ['1', '2']])->ids('itin'));
    }

    public function testIsComparesExactly(): void
    {
        $input = new Input(['fragment' => '1', 'other' => 'true']);

        self::assertTrue($input->is('fragment', '1'));
        self::assertFalse($input->is('other', '1'));
        self::assertFalse($input->is('missing', '1'));
    }

    public function testRawHandsBackWhateverArrived(): void
    {
        // The filter values are a string from a shared link and an array from a
        // checkbox group, and the caller handles both.
        $input = new Input(['airlines' => ['AC', 'BA'], 'stops' => '1']);

        self::assertSame(['AC', 'BA'], $input->raw('airlines'));
        self::assertSame('1', $input->raw('stops'));
        self::assertNull($input->raw('missing'));
    }
}
