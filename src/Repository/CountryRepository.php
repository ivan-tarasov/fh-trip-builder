<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

final readonly class CountryRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Every country, code to name, in alphabetical order by name.
     *
     * The billing-country select used to carry six countries written into the
     * template, which is both a short list and data living in a view. The table
     * behind this is already seeded with all of them and is what the airports
     * join against, so a card registered anywhere the app sells flights to can
     * now be entered.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT code, title FROM ' . Table::Countries->value . ' ORDER BY title ASC',
        );

        $countries = [];

        foreach ($rows as $row) {
            $countries[(string) $row['code']] = (string) $row['title'];
        }

        return $countries;
    }
}
