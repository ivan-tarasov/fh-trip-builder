<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\SubscriberRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The fare-alert list: adding an address, reading the list back, and taking
 * one off it.
 *
 * @phpstan-import-type SubscriberRow from SubscriberRepository
 */
final class SubscriberRepositoryTest extends IntegrationTestCase
{
    private const string SENTINEL = 'zzsub-';

    protected function tearDown(): void
    {
        // A skip raised from a teardown is a failure rather than a skip.
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute(
            'DELETE FROM subscribers WHERE email LIKE ?',
            [self::SENTINEL . '%'],
        );
    }

    private function subscribers(): SubscriberRepository
    {
        return new SubscriberRepository($this->connection());
    }

    private function email(string $suffix): string
    {
        return self::SENTINEL . $suffix . '@example.com';
    }

    public function testAddingTwiceIsNotAnError(): void
    {
        $email = $this->email('twice');

        self::assertTrue($this->subscribers()->add($email), 'the first add should say so');
        self::assertFalse($this->subscribers()->add($email), 'the second add should say it was already there');
    }

    /**
     * The local part is folded, so the same person cannot appear twice under
     * two spellings of one address.
     */
    public function testAnAddressIsStoredLowercased(): void
    {
        $subscribers = $this->subscribers();

        self::assertTrue($subscribers->add('Zzsub-Case@Example.com'));
        self::assertFalse($subscribers->add('zzsub-case@example.com'), 'same address, different case');
    }

    public function testAddedAddressesAreListedNewestFirst(): void
    {
        $subscribers = $this->subscribers();

        $subscribers->add($this->email('first'));
        $subscribers->add($this->email('second'));

        $rows = $subscribers->all(10);
        $ours = array_values(array_filter(
            $rows,
            static fn(array $row): bool => str_starts_with($row['email'], self::SENTINEL),
        ));

        self::assertSame([$this->email('second'), $this->email('first')], array_column($ours, 'email'));
    }

    public function testCountAllMatchesTheTable(): void
    {
        $subscribers = $this->subscribers();
        $before = $subscribers->countAll();

        $subscribers->add($this->email('counted'));

        self::assertSame($before + 1, $subscribers->countAll());
    }

    public function testRemovingTakesTheRowOffTheList(): void
    {
        $subscribers = $this->subscribers();
        $email = $this->email('removeme');

        $subscribers->add($email);

        $row = self::findByEmail($subscribers, $email);
        self::assertNotNull($row, 'the row should be findable before it is removed');

        self::assertTrue($subscribers->remove($row['id']));
        self::assertNull(self::findByEmail($subscribers, $email), 'the row should be gone');
    }

    /**
     * A stale or invented id says so, rather than pretending to have removed
     * something.
     */
    public function testRemovingAnIdThatIsNotThereSaysSo(): void
    {
        self::assertFalse($this->subscribers()->remove(0));
    }

    public function testRemoveManyTakesEveryRowOffInOneCall(): void
    {
        $subscribers = $this->subscribers();
        $subscribers->add($this->email('bulk-one'));
        $subscribers->add($this->email('bulk-two'));

        $one = self::findByEmail($subscribers, $this->email('bulk-one'));
        $two = self::findByEmail($subscribers, $this->email('bulk-two'));
        self::assertNotNull($one);
        self::assertNotNull($two);

        self::assertSame(2, $subscribers->removeMany([$one['id'], $two['id']]));
        self::assertNull(self::findByEmail($subscribers, $this->email('bulk-one')));
        self::assertNull(self::findByEmail($subscribers, $this->email('bulk-two')));
    }

    public function testRemoveManyWithNoIdsTouchesNothing(): void
    {
        self::assertSame(0, $this->subscribers()->removeMany([]));
    }

    /** For the command palette (G4.1, #312) -- a substring match, newest first, capped. */
    public function testSearchFindsAnAddressByASubstring(): void
    {
        $subscribers = $this->subscribers();
        $subscribers->add($this->email('findme'));
        $subscribers->add($this->email('other'));

        $found = $subscribers->search('findme', 10);

        self::assertCount(1, $found);
        self::assertSame($this->email('findme'), $found[0]['email']);
    }

    public function testSearchRespectsItsLimit(): void
    {
        $subscribers = $this->subscribers();
        $subscribers->add($this->email('cap-one'));
        $subscribers->add($this->email('cap-two'));
        $subscribers->add($this->email('cap-three'));

        self::assertCount(2, $subscribers->search(self::SENTINEL . 'cap', 2));
    }

    public function testSearchWithNoMatchIsAnEmptyList(): void
    {
        self::assertSame([], $this->subscribers()->search(self::SENTINEL . 'nobody-here', 10));
    }

    /** For the removal log (G5.1, #316) -- read before the row is gone to log it. */
    public function testEmailsForReadsTheAddressBeforeRemoval(): void
    {
        $subscribers = $this->subscribers();
        $subscribers->add($this->email('logme'));

        $row = self::findByEmail($subscribers, $this->email('logme'));
        self::assertNotNull($row);

        self::assertSame([$row['id'] => $this->email('logme')], $subscribers->emailsFor([$row['id']]));
    }

    public function testEmailsForWithNoIdsIsAnEmptyList(): void
    {
        self::assertSame([], $this->subscribers()->emailsFor([]));
    }

    public function testEmailsForSkipsAnIdThatIsNotThere(): void
    {
        self::assertSame([], $this->subscribers()->emailsFor([0]));
    }

    /** @return SubscriberRow|null */
    private static function findByEmail(SubscriberRepository $subscribers, string $email): ?array
    {
        foreach ($subscribers->all(1000) as $row) {
            if ($row['email'] === $email) {
                return $row;
            }
        }

        return null;
    }
}
