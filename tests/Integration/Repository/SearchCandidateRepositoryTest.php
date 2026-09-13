<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use DateTimeImmutable;
use TripBuilder\Repository\SearchCandidateRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The remembering itself, against a real table.
 *
 * Three things are worth proving and none of them are the SQL. That an answer
 * comes back the shape it went in, across a blob and two lossy-looking steps.
 * That writing a flight is enough to make every stored answer unreachable,
 * which is what lets this be a cache rather than a delay. And that a row it
 * cannot read is a miss and not an exception -- a cache that can break a search
 * is worse than no cache (E31, #219).
 */
final class SearchCandidateRepositoryTest extends IntegrationTestCase
{
    private const string SQL = 'SELECT 1 FROM flights WHERE departure_airport = ?';
    private const array PARAMS = ['YUL'];

    /** @var list<string> */
    private array $written = [];

    /** @var list<int> */
    private array $flights = [];

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        foreach ($this->written as $key) {
            $this->connection()->execute('DELETE FROM search_candidates WHERE id = ?', [$key]);
        }

        foreach ($this->flights as $id) {
            $this->connection()->execute('DELETE FROM flights WHERE id = ?', [$id]);
        }
    }

    public function testAnAnswerComesBackTheShapeItWentIn(): void
    {
        $candidates = [
            ['id' => 41, 'price' => '210.55', 'stops' => 0, 'carriers' => 'AC'],
            ['id' => 42, 'price' => '198.00', 'stops' => 1, 'carriers' => 'AC,WS'],
        ];

        $key = $this->remember($candidates);

        self::assertSame($candidates, $this->candidates()->get($key));
    }

    public function testAnAnswerNobodyWroteIsAMiss(): void
    {
        self::assertNull($this->candidates()->get(str_repeat('0', 64)));
    }

    /**
     * The point of the whole design: new flights retire every stored answer.
     */
    public function testWritingAFlightMovesTheKey(): void
    {
        $before = $this->candidates()->keyFor(self::SQL, self::PARAMS);

        $this->flights[] = $this->connection()->insert(
            'INSERT INTO flights (airline, number, departure_airport, departure_time,'
            . ' arrival_airport, arrival_time, distance, duration, cabins, price_base, price_tax, rating)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['AC', 100, 'YUL', '2026-09-15 06:00:00', 'YYZ', '2026-09-15 07:15:00', 504, 75, 1, 20.00, 3.00, 4.10],
        );

        self::assertNotSame($before, $this->candidates()->keyFor(self::SQL, self::PARAMS));
    }

    /**
     * The same statement asked twice, with nothing written in between.
     */
    public function testTheKeyHoldsStillWhileTheFlightsDo(): void
    {
        $candidates = $this->candidates();

        self::assertSame(
            $candidates->keyFor(self::SQL, self::PARAMS),
            $candidates->keyFor(self::SQL, self::PARAMS),
        );
    }

    public function testDifferentParametersAnswerToDifferentKeys(): void
    {
        $candidates = $this->candidates();

        self::assertNotSame(
            $candidates->keyFor(self::SQL, ['YUL']),
            $candidates->keyFor(self::SQL, ['YYZ']),
        );
    }

    public function testAnAnswerPutTwiceKeepsTheSecond(): void
    {
        $key = $this->remember([['id' => 1]]);
        $this->candidates()->put($key, [['id' => 2]]);

        self::assertSame([['id' => 2]], $this->candidates()->get($key));
    }

    public function testARowThatCannotBeReadIsAMiss(): void
    {
        $key = $this->remember([['id' => 1]]);

        $this->connection()->execute(
            'UPDATE search_candidates SET candidates = ? WHERE id = ?',
            ['not anything that was ever compressed', $key],
        );

        self::assertNull($this->candidates()->get($key));
    }

    public function testAnAnswerPastTheWindowIsNotRead(): void
    {
        $key = $this->remember([['id' => 1]]);
        $this->age($key, SearchCandidateRepository::KEEP_MINUTES + 1);

        self::assertNull($this->candidates()->get($key));
    }

    public function testOldAnswersAreCountedAndSwept(): void
    {
        $key = $this->remember([['id' => 1]]);
        $fresh = $this->remember([['id' => 2]], 'SELECT 2');

        $candidates = $this->candidates();
        $before = $candidates->countStale();

        $this->age($key, SearchCandidateRepository::KEEP_MINUTES + 1);

        self::assertSame($before + 1, $candidates->countStale());
        self::assertGreaterThanOrEqual(1, $candidates->forgetStale());
        self::assertNull($candidates->get($key));
        self::assertSame([['id' => 2]], $candidates->get($fresh));
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function remember(array $candidates, string $sql = self::SQL): string
    {
        $key = $this->candidates()->keyFor($sql, self::PARAMS);
        $this->candidates()->put($key, $candidates);
        $this->written[] = $key;

        return $key;
    }

    private function age(string $key, int $minutes): void
    {
        $this->connection()->execute(
            'UPDATE search_candidates SET built_at = ? WHERE id = ?',
            [new DateTimeImmutable('-' . $minutes . ' minutes')->format('Y-m-d H:i:s'), $key],
        );
    }

    private function candidates(): SearchCandidateRepository
    {
        return new SearchCandidateRepository($this->connection());
    }
}
