<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use JsonException;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Every config key an operator has overridden, and the log of how it got
 * that way.
 *
 * @phpstan-type SettingChangeRow array{
 *     setting_key: string, old_value: ?string, new_value: ?string, changed_at: string,
 * }
 */
final readonly class SettingsRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Every override on the table, decoded.
     *
     * Empty on a fresh install, which is what makes an untouched one behave
     * exactly as it always has -- there is nothing here to fall back from
     * (see Settings).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var list<array{setting_key: string, value: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT setting_key, value FROM ' . Table::Settings->value,
        );

        $overrides = [];

        foreach ($rows as $row) {
            $overrides[$row['setting_key']] = json_decode($row['value'], true);
        }

        return $overrides;
    }

    /**
     * Write one override, and log the change -- unless it changes nothing.
     *
     * Comparing the encoded form and not the value itself: `1` and `'1'`
     * decode to different things, and encoding first is what makes "nothing
     * changed" mean what the operator would mean by it.
     *
     * @throws JsonException
     */
    public function set(string $key, mixed $value): void
    {
        $new = json_encode($value, JSON_THROW_ON_ERROR);
        $old = $this->rawValue($key);

        if ($old === $new) {
            return;
        }

        $this->connection->execute(
            'INSERT INTO ' . Table::Settings->value . ' (setting_key, value, updated_at) VALUES (?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$key, $new],
        );

        $this->logChange($key, $old, $new);
    }

    /**
     * Take an override off, so the key reads as its config default again.
     */
    public function remove(string $key): void
    {
        $old = $this->rawValue($key);

        if ($old === null) {
            return;
        }

        $this->connection->execute(
            'DELETE FROM ' . Table::Settings->value . ' WHERE setting_key = ?',
            [$key],
        );

        $this->logChange($key, $old, null);
    }

    /**
     * The most recent changes, across every key, newest first.
     *
     * `null` omits the `LIMIT` entirely -- the full log, for an export
     * (G3.5, #308) rather than the page's own capped-at-20 read.
     *
     * @return list<SettingChangeRow>
     */
    public function history(?int $limit): array
    {
        $sql = 'SELECT setting_key, old_value, new_value, changed_at FROM ' . Table::SettingChanges->value
            . ' ORDER BY changed_at DESC, id DESC';

        /** @var list<SettingChangeRow> $rows */
        $rows = $limit === null
            ? $this->connection->fetchAll($sql)
            : $this->connection->fetchAll($sql . ' LIMIT ?', [$limit]);

        return $rows;
    }

    /** The stored JSON for one key, or null when it has no override. */
    private function rawValue(string $key): ?string
    {
        /** @var string|null $value */
        $value = $this->connection->fetchValue(
            'SELECT value FROM ' . Table::Settings->value . ' WHERE setting_key = ?',
            [$key],
        );

        return $value;
    }

    private function logChange(string $key, ?string $old, ?string $new): void
    {
        $this->connection->execute(
            'INSERT INTO ' . Table::SettingChanges->value
            . ' (setting_key, old_value, new_value, changed_at) VALUES (?, ?, ?, NOW())',
            [$key, $old, $new],
        );
    }
}
