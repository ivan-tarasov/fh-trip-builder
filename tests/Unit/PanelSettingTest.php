<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TripBuilder\PanelSetting;

/**
 * Parsing what a form posts, and saying what is wrong with it, for every
 * field the settings page offers.
 */
final class PanelSettingTest extends TestCase
{
    /**
     * Every case answers every method without hitting an unhandled `match`
     * arm -- the failure a new case added without updating one of these
     * would cause, and the one this test exists to catch before a page does.
     */
    public function testEveryCaseAnswersItsOwnMetadata(): void
    {
        foreach (PanelSetting::cases() as $setting) {
            self::assertNotSame('', $setting->group(), $setting->name . ' has no group');
            self::assertNotSame('', $setting->label(), $setting->name . ' has no label');
            self::assertNotSame('', $setting->reason(), $setting->name . ' has no reason');
        }
    }

    public function testAWholeNumberFieldParsesAndValidates(): void
    {
        $setting = PanelSetting::MaxStops;
        $parsed = $setting->parse('2');

        self::assertSame(2, $parsed);
        self::assertNull($setting->invalidBecause($parsed));
    }

    public function testAWholeNumberFieldRejectsSomethingThatIsNotOne(): void
    {
        $setting = PanelSetting::MaxStops;

        self::assertNotNull($setting->invalidBecause($setting->parse('two')));
        self::assertNotNull($setting->invalidBecause($setting->parse('-1')));
    }

    public function testARatioFieldMustBeGreaterThanOne(): void
    {
        $setting = PanelSetting::MaxDetourRatio;

        self::assertNull($setting->invalidBecause($setting->parse('1.6')));
        self::assertNotNull($setting->invalidBecause($setting->parse('1.0')));
        self::assertNotNull($setting->invalidBecause($setting->parse('0.5')));
    }

    public function testAnHourFieldMustBeZeroToTwentyThree(): void
    {
        $setting = PanelSetting::NightFromHour;

        self::assertNull($setting->invalidBecause($setting->parse('23')));
        self::assertNull($setting->invalidBecause($setting->parse('0')));
        self::assertNotNull($setting->invalidBecause($setting->parse('24')));
        self::assertNotNull($setting->invalidBecause($setting->parse('-1')));
    }

    public function testGulfCountriesParsesOnePerLineAndUppercases(): void
    {
        $setting = PanelSetting::GulfCountries;
        $parsed = $setting->parse("ae\nsa\n \nqa");

        self::assertSame(['AE', 'SA', 'QA'], $parsed);
        self::assertNull($setting->invalidBecause($parsed));
    }

    public function testGulfCountriesRejectsAnythingThatIsNotTwoLetters(): void
    {
        $setting = PanelSetting::GulfCountries;

        self::assertNotNull($setting->invalidBecause($setting->parse('United Arab Emirates')));
        self::assertNotNull($setting->invalidBecause($setting->parse('A')));
    }

    public function testMetaKeywordsAcceptsAnyNonEmptyList(): void
    {
        $setting = PanelSetting::MetaKeywords;
        $parsed = $setting->parse("FlightHub\nassessment");

        self::assertSame(['FlightHub', 'assessment'], $parsed);
        self::assertNull($setting->invalidBecause($parsed));
    }

    public function testATextFieldCannotBeEmpty(): void
    {
        $setting = PanelSetting::AppName;

        self::assertNull($setting->invalidBecause($setting->parse('Trip Builder')));
        self::assertNotNull($setting->invalidBecause($setting->parse('   ')));
    }

    public function testFormatAndParseRoundTripANumber(): void
    {
        $setting = PanelSetting::MaxStops;

        self::assertSame('2', $setting->format($setting->parse('2')));
    }

    public function testFormatDropsTrailingZeroesFromTheRatio(): void
    {
        self::assertSame('1.6', PanelSetting::MaxDetourRatio->format(1.6));
        self::assertSame('2', PanelSetting::MaxDetourRatio->format(2.0));
    }

    public function testFormatJoinsAListWithNewlines(): void
    {
        self::assertSame("AE\nSA", PanelSetting::GulfCountries->format(['AE', 'SA']));
    }
}
