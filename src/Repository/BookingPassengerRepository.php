<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * The travellers on a booking.
 *
 * A booking's own row keeps the lead passenger, so the bookings list can render
 * a name without a join. Everyone, the lead included, is here.
 *
 * Verified live against a real fetch: `id`, `booking_id` and `position` come
 * back native PHP `int` (plain INT/TINYINT columns, same as
 * `AirportRepository`'s `altitude`), and `dob` -- a DATE column -- comes back
 * `string`, not a `DateTime`.
 *
 * `keyFor()` reads only `first_name`, `last_name` and `dob`, and is called with
 * three structurally different shapes: a full `BookingPassengerRow`, a
 * `TravellerBookingCountRow`, and a bare `SubmittedPassenger` in tests. `...`
 * marks the shape open rather than naming a fourth alias just for that
 * intersection.
 *
 * @phpstan-type BookingPassengerRow array{
 *     id: int, booking_id: int, position: int, type: string,
 *     first_name: string, last_name: string, dob: string, gender: string,
 * }
 * @phpstan-type SubmittedPassenger array{
 *     type: string, first_name: string, last_name: string, dob: string, gender: string,
 * }
 * @phpstan-type TravellerCountRow array{booking_id: int, travellers: int}
 * @phpstan-type TravellerNameRow array{booking_id: int, first_name: string, last_name: string}
 * @phpstan-type TravellerBookingCountRow array{
 *     first_name: string, last_name: string, dob: string, bookings: int,
 * }
 * @phpstan-type PassengerNameKey array{first_name: string, last_name: string, dob: string, ...}
 */
final readonly class BookingPassengerRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Write a booking's travellers in the order they were entered.
     *
     * The caller owns the transaction: this runs one statement per passenger
     * and a booking with half its party recorded is worse than no booking.
     *
     * @param list<SubmittedPassenger> $passengers
     */
    public function createFor(int $bookingId, array $passengers): void
    {
        $sql = 'INSERT INTO ' . Table::BookingPassengers->value
            . ' (booking_id, position, type, first_name, last_name, dob, gender)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)';

        foreach (array_values($passengers) as $index => $passenger) {
            $this->connection->execute($sql, [
                $bookingId,
                $index + 1,
                $passenger['type'],
                $passenger['first_name'],
                $passenger['last_name'],
                $passenger['dob'],
                $passenger['gender'],
            ]);
        }
    }

    /**
     * How many travellers each of these bookings carries.
     *
     * One query for the whole page rather than one per booking: the bookings
     * list reads its rows without joining -- which is why the lead is kept on
     * the booking itself -- so this is what lets a card say a party is larger
     * than the one name it shows.
     *
     * @param list<int> $bookingIds
     * @return array<int, int> booking id => traveller count, absent when none
     */
    public function countsFor(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($bookingIds), '?'));

        /** @var list<TravellerCountRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT booking_id, COUNT(*) AS travellers FROM ' . Table::BookingPassengers->value
            . " WHERE booking_id IN ($placeholders) GROUP BY booking_id",
            $bookingIds,
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['booking_id']] = (int) $row['travellers'];
        }

        return $counts;
    }

    /**
     * Everyone on each of these bookings, in the order they were entered.
     *
     * One query for the whole page, like `countsFor()` beside it, and it
     * answers the same question with more of the answer -- so the panel's list
     * asks this one instead of both. A booking written before this table
     * existed comes back absent rather than empty-handed: its only traveller is
     * the lead on the booking's own row (A3.8, #233).
     *
     * @param list<int> $bookingIds
     * @return array<int, list<string>> booking id => names, lead first
     */
    public function namesFor(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($bookingIds), '?'));

        /** @var list<TravellerNameRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT booking_id, first_name, last_name FROM ' . Table::BookingPassengers->value
            . " WHERE booking_id IN ($placeholders) ORDER BY booking_id ASC, position ASC",
            $bookingIds,
        );

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row['booking_id']][] = trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name'],
            );
        }

        return $names;
    }

    /**
     * How many bookings each of these travellers appears on.
     *
     * **Matched on the name and the date of birth together, and that is a
     * match on what was typed rather than on a person.** There is no passenger
     * table and no identity here: a booking carries the words somebody entered
     * into a form. Two people can share a name, one person can be entered two
     * ways, and this data already proves the first half -- one booking in it
     * carries `Felix Okafor` twice, born thirty-one years apart.
     *
     * So the name alone would be wrong, and the name with the date of birth is
     * the closest thing to a person the schema holds. The panel says which it
     * is rather than calling the number a person's history (A3.8, #233).
     *
     * One query for the party rather than one per traveller, which is what the
     * row constructor in the `IN` is for.
     *
     * @param list<BookingPassengerRow> $passengers rows as forBooking returns them
     * @return array<string, int> "first|last|dob" => how many bookings
     */
    public function bookingCountsFor(array $passengers): array
    {
        $wanted = [];
        $values = [];

        foreach ($passengers as $passenger) {
            $key = self::keyFor($passenger);

            if (isset($wanted[$key])) {
                continue;
            }

            $wanted[$key] = 0;
            $values = [
                ...$values,
                (string) ($passenger['first_name'] ?? ''),
                (string) ($passenger['last_name'] ?? ''),
                (string) ($passenger['dob'] ?? ''),
            ];
        }

        if ($wanted === []) {
            return [];
        }

        $tuples = implode(', ', array_fill(0, count($wanted), '(?, ?, ?)'));

        /** @var list<TravellerBookingCountRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT first_name, last_name, dob, COUNT(DISTINCT booking_id) AS bookings'
            . ' FROM ' . Table::BookingPassengers->value
            . " WHERE (first_name, last_name, dob) IN ($tuples)"
            . ' GROUP BY first_name, last_name, dob',
            $values,
        );

        foreach ($rows as $row) {
            $wanted[self::keyFor($row)] = (int) $row['bookings'];
        }

        return $wanted;
    }

    /**
     * The three fields that stand in for a traveller, as one string.
     *
     * A key and not a lookup: it is built the same way from a row of this table
     * and from a row of the count above, so the two can be matched in PHP
     * without the query having to return the key itself.
     *
     * @param PassengerNameKey $passenger
     */
    public static function keyFor(array $passenger): string
    {
        return sprintf(
            '%s|%s|%s',
            $passenger['first_name'] ?? '',
            $passenger['last_name'] ?? '',
            $passenger['dob'] ?? '',
        );
    }

    /**
     * A booking's travellers, lead first.
     *
     * @return list<BookingPassengerRow>
     */
    public function forBooking(int $bookingId): array
    {
        /** @var list<BookingPassengerRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::BookingPassengers->value
            . ' WHERE booking_id = ? ORDER BY position ASC',
            [$bookingId],
        );

        return $rows;
    }
}
