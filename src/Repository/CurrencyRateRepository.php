<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * What a Canadian dollar bought, per currency and per day.
 *
 * The table is a history, so every read here has to say which day it wants.
 * "Now" is the most recent row per code, which is a window function rather than
 * a filter against MAX(rate_date): on a table seeded in one go every row shares
 * a date, and a code compared against the whole table's maximum would match
 * every row it has -- the same trap AirportRepository::mostSearched() documents.
 */
final readonly class CurrencyRateRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * The newest rate for every currency the table holds.
     *
     * Cast to float on the way out, because PDO hands a DECIMAL column back as
     * a string and Money's constructor is typed -- see the note on
     * MapView::round() about the five map pages that threw for exactly this.
     *
     * @return array<string, float>
     */
    public function latest(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT x.code, x.rate FROM ('
            . ' SELECT code, rate,'
            . '  ROW_NUMBER() OVER (PARTITION BY code ORDER BY rate_date DESC) AS rn'
            . ' FROM ' . Table::CurrencyRates->value
            . ' ) x WHERE x.rn = 1',
        );

        $rates = [];

        foreach ($rows as $row) {
            $rates[(string) $row['code']] = (float) $row['rate'];
        }

        return $rates;
    }

    /**
     * The most recent day any rate was published for.
     *
     * What the switcher panel names, so a visitor can see how old the figures
     * are. Null where nothing has ever been stored, which is a different
     * statement from a date and reads differently in the panel.
     */
    public function latestDate(): ?string
    {
        $date = $this->connection->fetchValue(
            'SELECT MAX(rate_date) FROM ' . Table::CurrencyRates->value,
        );

        return is_string($date) ? $date : null;
    }

    /**
     * Write a day's rates.
     *
     * Upserted rather than inserted, so running the command twice on one
     * afternoon corrects the row instead of failing on the key. `VALUES(col)`
     * rather than the row alias MySQL 8 prefers, because MariaDB has no alias
     * syntax and the other repositories here settled that the same way.
     *
     * @param array<string, float> $rates code => units per 1 CAD
     * @return int rows written
     */
    public function store(array $rates, string $rateDate): int
    {
        $written = 0;

        foreach ($rates as $code => $rate) {
            $written += $this->connection->execute(
                'INSERT INTO ' . Table::CurrencyRates->value
                . ' (code, rate_date, rate, fetched_at) VALUES (?, ?, ?, NOW())'
                . ' ON DUPLICATE KEY UPDATE rate = VALUES(rate), fetched_at = NOW()',
                [$code, $rateDate, $rate],
            ) > 0 ? 1 : 0;
        }

        return $written;
    }
}
