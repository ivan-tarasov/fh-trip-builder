<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use TripBuilder\Repository\SettingsRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The overrides table, and the log of how it got that way.
 *
 * @phpstan-import-type SettingChangeRow from SettingsRepository
 */
final class SettingsRepositoryTest extends IntegrationTestCase
{
    private const string KEY = 'zzsetting.test.value';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute('DELETE FROM settings WHERE setting_key = ?', [self::KEY]);
        $this->connection()->execute('DELETE FROM setting_changes WHERE setting_key = ?', [self::KEY]);
    }

    private function settings(): SettingsRepository
    {
        return new SettingsRepository($this->connection());
    }

    public function testAKeyWithNoOverrideIsAbsentFromAll(): void
    {
        self::assertArrayNotHasKey(self::KEY, $this->settings()->all());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function valueShapes(): array
    {
        return [
            'an int' => [2],
            'a float' => [1.6],
            'a string' => ['mapbox/streets-v12'],
            'a list' => [['AE', 'SA']],
        ];
    }

    #[DataProvider('valueShapes')]
    public function testASetValueReadsBackTheSameShape(mixed $value): void
    {
        $settings = $this->settings();
        $settings->set(self::KEY, $value);

        self::assertSame($value, $settings->all()[self::KEY]);
    }

    public function testSettingTheSameValueTwiceLogsNothing(): void
    {
        $settings = $this->settings();

        $settings->set(self::KEY, 5);
        $settings->set(self::KEY, 5);

        self::assertCount(1, $settings->history(50));
    }

    public function testChangingAValueLogsTheOldAndTheNew(): void
    {
        $settings = $this->settings();

        $settings->set(self::KEY, 5);
        $settings->set(self::KEY, 9);

        $entry = self::mostRecent($settings, self::KEY);

        self::assertNotNull($entry);
        self::assertSame('5', $entry['old_value']);
        self::assertSame('9', $entry['new_value']);
    }

    public function testTheFirstWriteLogsNoOldValue(): void
    {
        $settings = $this->settings();
        $settings->set(self::KEY, 5);

        $entry = self::mostRecent($settings, self::KEY);

        self::assertNotNull($entry);
        self::assertNull($entry['old_value']);
    }

    public function testRemovingTakesTheOverrideOff(): void
    {
        $settings = $this->settings();
        $settings->set(self::KEY, 5);

        $settings->remove(self::KEY);

        self::assertArrayNotHasKey(self::KEY, $settings->all());
    }

    public function testRemovingLogsNoNewValue(): void
    {
        $settings = $this->settings();
        $settings->set(self::KEY, 5);
        $settings->remove(self::KEY);

        $entry = self::mostRecent($settings, self::KEY);

        self::assertNotNull($entry);
        self::assertNull($entry['new_value']);
    }

    /**
     * A key with no override to remove is not a change, so it is not logged.
     */
    public function testRemovingAKeyThatWasNeverSetLogsNothing(): void
    {
        $settings = $this->settings();
        $settings->remove(self::KEY);

        self::assertNull(self::mostRecent($settings, self::KEY));
    }

    /** @return SettingChangeRow|null */
    private static function mostRecent(SettingsRepository $settings, string $key): ?array
    {
        foreach ($settings->history(50) as $entry) {
            if ($entry['setting_key'] === $key) {
                return $entry;
            }
        }

        return null;
    }
}
