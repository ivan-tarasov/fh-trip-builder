<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Api\Flights\FareRules;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The seeded fare brands, keyed by code.
 *
 * There are a handful of rows and every checkout reads them, so they are read
 * once per request and held.
 */
final readonly class FareBrandRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * @return array<string, FareRules>
     */
    public function all(): array
    {
        static $brands = null;

        if ($brands !== null) {
            return $brands;
        }

        $brands = [];

        foreach ($this->connection->fetchAll('SELECT * FROM ' . Table::FareBrands->value) as $row) {
            $brands[(string) $row['code']] = FareRules::fromRow($row);
        }

        return $brands;
    }

    /**
     * The rules holding across a set of legs, or null when none of them names a
     * brand the seed knows — an older generated row, or a code since removed.
     *
     * @param list<string|null> $codes one per leg, in journey order
     */
    public function rulesFor(array $codes): ?FareRules
    {
        $known = $this->all();
        $legs = [];

        foreach ($codes as $code) {
            if ($code !== null && isset($known[$code])) {
                $legs[] = $known[$code];
            }
        }

        return $legs === [] ? null : FareRules::strictest($legs);
    }
}
