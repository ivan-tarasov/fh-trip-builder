<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration;

use TripBuilder\Config;
use TripBuilder\Repository\SettingsRepository;
use TripBuilder\Settings;

/**
 * Reading a key that might have been overridden, against a real table.
 *
 * `PanelSettingTest` covers parsing and validating what a form posts; this is
 * the other half, whether an override actually reaches `Settings::get()`
 * once it is on the table, and whether an untouched key still answers
 * exactly what `Config::get()` would (A3.7, #232).
 */
final class SettingsTest extends IntegrationTestCase
{
    private const string KEY = 'search.connections.max_stops';

    protected function setUp(): void
    {
        new Config('common');
        Settings::forget();
    }

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM settings WHERE setting_key = ?', [self::KEY]);
        $this->connection()->execute('DELETE FROM setting_changes WHERE setting_key = ?', [self::KEY]);
        Settings::forget();
    }

    public function testAnUntouchedKeyAnswersTheConfigValue(): void
    {
        self::assertSame(Config::get(self::KEY), Settings::get(self::KEY));
    }

    public function testAnOverriddenKeyAnswersTheOverride(): void
    {
        new SettingsRepository($this->connection())->set(self::KEY, 4);

        self::assertNotSame(4, Config::get(self::KEY), 'the fixture should actually change the answer');
        self::assertSame(4, Settings::get(self::KEY));
    }

    /**
     * The read is memoised, so a row written after the first ask is not
     * seen until something calls forget() -- the same reason Money::active()
     * needs one.
     */
    public function testAnOverrideWrittenAfterTheFirstReadIsNotSeenUntilForgotten(): void
    {
        Settings::get(self::KEY);

        new SettingsRepository($this->connection())->set(self::KEY, 4);
        self::assertNotSame(4, Settings::get(self::KEY), 'the memoised read should still be in effect');

        Settings::forget();
        self::assertSame(4, Settings::get(self::KEY));
    }

    public function testRemovingTheOverrideFallsBackToConfigAgain(): void
    {
        new SettingsRepository($this->connection())->set(self::KEY, 4);
        Settings::forget();
        self::assertSame(4, Settings::get(self::KEY));

        new SettingsRepository($this->connection())->remove(self::KEY);
        Settings::forget();

        self::assertSame(Config::get(self::KEY), Settings::get(self::KEY));
    }
}
