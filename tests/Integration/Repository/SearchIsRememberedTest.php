<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Api\Flights\SortMethod;
use TripBuilder\CabinClass;
use TripBuilder\Repository\FlightRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * That the search really reads what it wrote.
 *
 * Asserting that two identical searches agree proves nothing -- they would
 * agree with no cache at all. So the stored answer is replaced with a wrong
 * one between the two searches: if the second search still finds flights, it
 * went to the database and the cache is decoration.
 *
 * Then the other half, which is the part that pays: a second page and a filter
 * change both read the same stored answer rather than running the statement
 * again. That is what E31 (#219) was for -- "show more" used to re-run the
 * whole search and slice a different window out of it.
 */
final class SearchIsRememberedTest extends IntegrationTestCase
{
    /**
     * Past the horizon, so no generated flight can share this day.
     *
     * A date of the test's own, so nothing else is in the answer it stores.
     *
     * Memoised, so a run that crosses midnight cannot insert on one date and
     * search on the next.
     */
    private static ?string $departDate = null;

    private static function departDate(): string
    {
        return self::$departDate ??= self::dateBeyondGeneratedFlights(28);
    }

    /** @var list<int> */
    private array $flights = [];

    protected function setUp(): void
    {
        if ($this->connectionOrNull() === null) {
            return;
        }

        // Priced far below the generated network so they rank first, and on a
        // date of their own so nothing else is in the answer.
        $this->flights[] = $this->insertFlight('AC', '06:00:00', '07:15:00');
        $this->flights[] = $this->insertFlight('WS', '12:00:00', '13:20:00');
        $this->flights[] = $this->insertFlight('AC', '18:00:00', '19:20:00');

        $this->connection()->execute('DELETE FROM search_candidates');
    }

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ($this->flights as $id) {
            $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$id]);
        }

        $this->connection()->execute('DELETE FROM search_candidates');
    }

    public function testASearchStoresItsCandidates(): void
    {
        $this->search();

        self::assertSame(1, $this->stored(), 'A search wrote no candidates, or wrote more than one set.');
    }

    /**
     * Replace the answer and the next search must believe it.
     */
    public function testTheSecondSearchReadsTheStoredAnswer(): void
    {
        self::assertGreaterThanOrEqual(3, $this->search()['total']);

        $this->connection()->execute(
            'UPDATE search_candidates SET candidates = ?',
            [gzcompress(serialize([]), 6)],
        );

        self::assertSame(0, $this->search()['total'], 'The second search ignored the stored answer.');
    }

    /**
     * The reason the cache sits where it does: a page is a slice of one answer.
     */
    public function testASecondPageReadsTheSameStoredAnswer(): void
    {
        $this->search();

        $this->connection()->execute(
            'UPDATE search_candidates SET candidates = ?',
            [gzcompress(serialize([]), 6)],
        );

        self::assertSame(0, $this->search(offset: 2)['total'], 'Paging ran the statement again.');
        self::assertSame(1, $this->stored(), 'Paging stored a second answer.');
    }

    /**
     * And a different sort does not, because the sort is in the statement.
     */
    public function testADifferentSortIsADifferentAnswer(): void
    {
        $this->search();
        $this->search(sort: SortMethod::Duration);

        self::assertSame(2, $this->stored());
    }

    /** @return array<string, mixed> */
    private function search(int $offset = 0, SortMethod $sort = SortMethod::Price): array
    {
        return new FlightRepository($this->connection())->searchDirection(
            'YUL',
            'YYZ',
            self::departDate(),
            $sort,
            $offset,
            2,
            CabinClass::Economy,
        );
    }

    private function stored(): int
    {
        return (int) $this->connection()->fetchValue('SELECT COUNT(*) FROM search_candidates');
    }

    private function insertFlight(string $airline, string $departure, string $arrival): int
    {
        return $this->connection()->insert(
            'INSERT INTO flights (airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, cabins, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $airline, 100, 'YUL', self::departDate() . ' ' . $departure,
                'YYZ', self::departDate() . ' ' . $arrival, 504, 75, 1, 20.00, 3.00, 4.10,
            ],
        );
    }
}
