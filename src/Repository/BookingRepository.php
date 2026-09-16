<?php

declare(strict_types=1);

namespace TripBuilder\Repository;

use DateTimeImmutable;
use RuntimeException;
use TripBuilder\BookingStatus;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;

/**
 * Live-verified against a real fetch: `id` is a plain `int` column and stays
 * `int`; `session_id`, `reference`, `status`, `contact_email`,
 * `contact_phone`, `passenger_first`, `passenger_last`, `passenger_gender`,
 * `currency`, `language` and `card_brand`/`card_last4` are non-nullable
 * `varchar`/`char`; `departure_time` and `passenger_dob` are nullable,
 * `created` is not, and all three come back `string`, not a `DateTime`;
 * `flight_outbound` is a non-nullable `json` column and `flight_return`/
 * `fare_brand`/`fare_rules` are nullable ones, all read back as the raw JSON
 * `string`, not decoded; `ip_address`/`city`/`country` are plain nullable
 * `varchar`/`char`, not JSON; `price_base`, `price_tax` and `currency_rate`
 * are DECIMAL and stringify like every other DECIMAL column in this
 * codebase.
 *
 * @phpstan-type BookingRow array{
 *     id: int, session_id: string, departure_time: string|null,
 *     flight_outbound: string, flight_return: string|null,
 *     created: string, reference: string, status: string,
 *     contact_email: string, contact_phone: string,
 *     passenger_first: string, passenger_last: string,
 *     passenger_dob: string|null, passenger_gender: string,
 *     fare_brand: string|null, fare_rules: string|null,
 *     price_base: string, price_tax: string,
 *     currency: string, currency_rate: string,
 *     card_brand: string, card_last4: string,
 *     ip_address: string|null, city: string|null, country: string|null,
 *     language: string,
 * }
 */
final readonly class BookingRepository
{
    /**
     * How long a booking is kept after the flight has gone.
     *
     * The retention policy, in one place and written into the README beside
     * the install steps -- a policy nobody can find is not one (E9, #146).
     *
     * Ninety days rather than a year, because nothing here needs a year.
     * `bookings` and `booking_passengers` hold an email, a phone number, names
     * and dates of birth, and every read of them is scoped by `session_id`:
     * once a visitor's session is gone there is no query in this application
     * that can reach the row again, and no admin panel to add one. So the only
     * purpose the data still serves after departure is a visitor coming back
     * to a trip they took, and ninety days is generous for that.
     */
    public const int KEEP_DAYS_AFTER_DEPARTURE = 90;

    public function __construct(private Connection $connection) {}

    /**
     * Bookings for a session, earliest departure first.
     *
     * @return list<BookingRow>
     */
    public function forSession(string $sessionId): array
    {
        /** @var list<BookingRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value . ' WHERE session_id = ? ORDER BY departure_time ASC',
            [$sessionId],
        );

        return $rows;
    }

    /**
     * Insert a booking from a column => value map; returns the new id.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $columns = array_keys($data);

        $sql = 'INSERT INTO ' . Table::Bookings->value
            . ' (' . implode(', ', array_map(static fn(string $c): string => "`$c`", $columns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return $this->connection->insert($sql, array_values($data));
    }

    /**
     * One booking by id, scoped to the session that made it -- an id from
     * somebody else's browser resolves to nothing.
     *
     * Keyed on the id rather than the reference because the rows written before
     * checkout issued references have none, and those bookings still belong to
     * whoever made them.
     *
     * @return BookingRow|null
     */
    public function findForSession(int $bookingId, string $sessionId): ?array
    {
        /** @var list<BookingRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value . ' WHERE id = ? AND session_id = ? LIMIT 1',
            [$bookingId, $sessionId],
        );

        return $rows[0] ?? null;
    }

    /**
     * One booking by its reference, scoped to the session that made it — the
     * confirmation page is reachable by URL and a reference is short enough to
     * guess.
     *
     * @return BookingRow|null
     */
    public function findByReference(string $reference, string $sessionId): ?array
    {
        /** @var list<BookingRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value . ' WHERE reference = ? AND session_id = ? LIMIT 1',
            [$reference, $sessionId],
        );

        return $rows[0] ?? null;
    }

    /**
     * A booking reference nobody else holds.
     *
     * Six characters from an alphabet with no O/0 or I/1, so a reference read
     * off a screen and typed back in cannot land on a different booking.
     */
    public function unusedReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        // A collision is vanishingly unlikely; looping is still cheaper than
        // explaining a duplicate key to whoever hits one.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $reference = '';

            for ($i = 0; $i < 6; $i++) {
                $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            /** @var int $taken */
            $taken = $this->connection->fetchValue(
                'SELECT COUNT(*) FROM ' . Table::Bookings->value . ' WHERE reference = ?',
                [$reference],
            );

            if ($taken === 0) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not allocate a booking reference.');
    }

    /**
     * Cancel a booking scoped to its session; returns affected row count.
     *
     * The row stays. What used to sit here deleted it outright, which left a
     * traveller no way to tell a cancelled trip from one that had never been
     * booked. Cancelling twice affects nothing, so the caller can tell "not
     * yours" and "already cancelled" apart from a real cancellation.
     */
    public function cancelForSession(int $bookingId, string $sessionId): int
    {
        return $this->connection->execute(
            'UPDATE ' . Table::Bookings->value
            . ' SET status = ? WHERE id = ? AND session_id = ? AND status <> ?',
            [
                BookingStatus::Cancelled->value,
                $bookingId,
                $sessionId,
                BookingStatus::Cancelled->value,
            ],
        );
    }

    /**
     * The most recent bookings, newest first, for the panel.
     *
     * **The first read here that is not scoped to one session**, and that is
     * the whole of what makes it new. Every other read answers "what has this
     * browser bought", because that is all the public site ever needs to know;
     * an operator looking one up is a different question and a different
     * responsibility (A3.8, #233).
     *
     * `created` and not `departure_time`: the panel is asked about bookings in
     * the order they were made, where the traveller's own list is about the
     * order they will be flown.
     *
     * @return list<BookingRow>
     */
    public function recent(int $limit, int $offset = 0): array
    {
        /** @var list<BookingRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value
            // Id second, so two bookings made in the same second come back in a
            // fixed order rather than whichever the engine offers.
            . ' ORDER BY created DESC, id DESC'
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
        );

        return $rows;
    }

    /** How many there are, so the panel can page through them. */
    public function countAll(): int
    {
        /** @var int $count */
        $count = $this->connection->fetchValue('SELECT COUNT(*) FROM ' . Table::Bookings->value);

        return $count;
    }

    /**
     * One booking, whoever made it.
     *
     * The unscoped twin of `findForSession()`. Kept apart rather than made a
     * nullable argument on that one, deliberately: an optional session is one
     * forgotten argument away from serving somebody else's booking to a
     * stranger, and this is only reachable from behind the panel's guard.
     *
     * @return BookingRow|null
     */
    public function find(int $bookingId): ?array
    {
        /** @var BookingRow|null $row */
        $row = $this->connection->fetchOne(
            'SELECT * FROM ' . Table::Bookings->value . ' WHERE id = ?',
            [$bookingId],
        );

        return $row;
    }

    /**
     * Move one booking's status from the panel.
     *
     * Unscoped, like `find()`, and narrowed the same way `cancelForSession()`
     * narrows: the update names the status it expects to find, so asking to
     * cancel an already-cancelled booking changes nothing and returns zero.
     * The caller logs only when a row actually moved -- a log of a change that
     * did not happen is worse than no log.
     */
    public function setStatus(int $bookingId, BookingStatus $to, BookingStatus $from): int
    {
        return $this->connection->execute(
            'UPDATE ' . Table::Bookings->value
            . ' SET status = ? WHERE id = ? AND status = ?',
            [$to->value, $bookingId, $from->value],
        );
    }

    /**
     * Bookings matching a search, newest first.
     *
     * What an operator has in front of them when somebody calls: a reference
     * off an email, a name, or the address they wrote from. The address is
     * searched and never listed -- see `AdminController::bookings()` on what a
     * list is for.
     *
     * `LIKE` with the term in the middle rather than a prefix, because a
     * surname typed into a support ticket is as often the second word as the
     * first. It scans; on a table this size that is nothing, and on one where
     * it is not, this is where a full-text index would go.
     *
     * @return list<BookingRow>
     */
    public function search(string $term, int $limit, int $offset = 0): array
    {
        $like = self::like($term);

        /** @var list<BookingRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT b.* FROM ' . Table::Bookings->value . ' b'
            . ' WHERE b.reference LIKE ? OR b.contact_email LIKE ?'
            . '  OR CONCAT(b.passenger_first, \' \', b.passenger_last) LIKE ?'
            . '  OR EXISTS ('
            . '   SELECT 1 FROM ' . Table::BookingPassengers->value . ' p'
            . '   WHERE p.booking_id = b.id'
            . '    AND CONCAT(p.first_name, \' \', p.last_name) LIKE ?'
            . '  )'
            . ' ORDER BY b.created DESC, b.id DESC'
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$like, $like, $like, $like],
        );

        return $rows;
    }

    /** How many bookings a search matches, for the pager. */
    public function countMatching(string $term): int
    {
        $like = self::like($term);

        /** @var int $count */
        $count = $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::Bookings->value . ' b'
            . ' WHERE b.reference LIKE ? OR b.contact_email LIKE ?'
            . '  OR CONCAT(b.passenger_first, \' \', b.passenger_last) LIKE ?'
            . '  OR EXISTS ('
            . '   SELECT 1 FROM ' . Table::BookingPassengers->value . ' p'
            . '   WHERE p.booking_id = b.id'
            . '    AND CONCAT(p.first_name, \' \', p.last_name) LIKE ?'
            . '  )',
            [$like, $like, $like, $like],
        );

        return $count;
    }

    /**
     * Every booking, newest first, for an export with no search term.
     *
     * `recent()` without the page: an export defeats its own point if it
     * only ever hands back one page at a time (G3.5, #308).
     *
     * @return list<BookingRow>
     */
    public function exportAll(): array
    {
        /** @var list<BookingRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . Table::Bookings->value . ' ORDER BY created DESC, id DESC',
        );

        return $rows;
    }

    /**
     * Every booking a search matches, newest first, for an export.
     *
     * `search()` without the page, same reasoning as {@see exportAll()}.
     *
     * @return list<BookingRow>
     */
    public function exportMatching(string $term): array
    {
        $like = self::like($term);

        /** @var list<BookingRow> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT b.* FROM ' . Table::Bookings->value . ' b'
            . ' WHERE b.reference LIKE ? OR b.contact_email LIKE ?'
            . '  OR CONCAT(b.passenger_first, \' \', b.passenger_last) LIKE ?'
            . '  OR EXISTS ('
            . '   SELECT 1 FROM ' . Table::BookingPassengers->value . ' p'
            . '   WHERE p.booking_id = b.id'
            . '    AND CONCAT(p.first_name, \' \', p.last_name) LIKE ?'
            . '  )'
            . ' ORDER BY b.created DESC, b.id DESC',
            [$like, $like, $like, $like],
        );

        return $rows;
    }

    /**
     * A search term as a `LIKE` pattern, with its wildcards as characters.
     *
     * `%` and `_` mean something to `LIKE`, and somebody searching for a
     * reference with an underscore in it means the underscore. Not a safety
     * matter -- the term is bound, never concatenated -- but a search that
     * quietly matches everything for a term of `%` is a search that lies about
     * what it did.
     */
    private static function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }

    /**
     * Bookings whose flight left before the cutoff, oldest first.
     *
     * Read before deleting so the sweep can say what it is about to remove.
     * Only what identifies the booking comes back -- there is no reason for a
     * command that exists to destroy personal information to print any.
     *
     * @return list<array{id: int, reference: string, departure_time: string}>
     */
    public function departedBefore(string $cutoff): array
    {
        /** @var list<array{id: int, reference: string, departure_time: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT id, reference, departure_time FROM ' . Table::Bookings->value
            . ' WHERE departure_time < ? ORDER BY departure_time ASC',
            [$cutoff],
        );

        return $rows;
    }

    /**
     * Remove those bookings and everyone travelling on them.
     *
     * Passengers first. There is no foreign key between the two tables, so
     * deleting the parent first would leave the children pointing at a booking
     * that is gone -- which is the shape of a bug that keeps the personal
     * information and loses the thing that explains it.
     *
     * @return array{bookings: int, passengers: int, events: int}
     */
    public function forgetDepartedBefore(string $cutoff): array
    {
        $expired = 'SELECT id FROM ' . Table::Bookings->value . ' WHERE departure_time < ?';

        $passengers = $this->connection->execute(
            'DELETE FROM ' . Table::BookingPassengers->value . ' WHERE booking_id IN (' . $expired . ')',
            [$cutoff],
        );

        // The log goes with it. The events carry no personal data, but a log of
        // a booking that has been forgotten is a record of a booking that has
        // been forgotten -- which is the thing this sweep exists to prevent
        // (A3.8, #233).
        $events = $this->connection->execute(
            'DELETE FROM ' . Table::BookingEvents->value . ' WHERE booking_id IN (' . $expired . ')',
            [$cutoff],
        );

        $bookings = $this->connection->execute(
            'DELETE FROM ' . Table::Bookings->value . ' WHERE departure_time < ?',
            [$cutoff],
        );

        return ['bookings' => $bookings, 'passengers' => $passengers, 'events' => $events];
    }

    /**
     * Bookings made in `[$from, $to)`.
     *
     * The hero's own cohort: every count and sum on it reads the same window
     * of `created`, so "made" and "cancelled" describe the same bookings
     * rather than two unrelated ones (G7.1, #332).
     */
    public function madeCount(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        /** @var int $count */
        $count = $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::Bookings->value . ' WHERE created >= ? AND created < ?',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );

        return $count;
    }

    /**
     * Of the bookings made in `[$from, $to)`, how many are cancelled now.
     *
     * Not `booking_events` -- that log "begins the day it is installed"
     * (see {@see BookingEventRepository::startedAt()}), so a cancellation
     * older than the log has no event row even though the booking plainly
     * is cancelled. The status column has no such gap (G7.1, #332).
     */
    public function cancelledCount(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        /** @var int $count */
        $count = $this->connection->fetchValue(
            'SELECT COUNT(*) FROM ' . Table::Bookings->value
            . ' WHERE created >= ? AND created < ? AND status = ?',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), BookingStatus::Cancelled->value],
        );

        return $count;
    }

    /**
     * The gross value of what was made in `[$from, $to)` -- cancelled or
     * not. A booking's price does not stop having been charged the moment
     * it is cancelled, and nothing on this hero claims to be a net figure.
     */
    public function totalCost(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        /** @var string $sum */
        $sum = $this->connection->fetchValue(
            'SELECT COALESCE(SUM(price_base + price_tax), 0) FROM ' . Table::Bookings->value
            . ' WHERE created >= ? AND created < ?',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );

        return (float) $sum;
    }

    /**
     * Every day in `[$from, $to)` and how many bookings were made on it,
     * zero for a day with none -- the same `date => value` shape
     * {@see CurrencyRateRepository::history()} already hands a sparkline,
     * so a chart reads a continuous run of days rather than sparse events.
     *
     * @return array<string, int>
     */
    public function dailyMadeCounts(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{d: string, c: int}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT DATE(created) AS d, COUNT(*) AS c FROM ' . Table::Bookings->value
            . ' WHERE created >= ? AND created < ? GROUP BY DATE(created)',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );

        /** @var array<string, int> */
        return self::continuousDays($from, $to, $rows);
    }

    /**
     * Every day in `[$from, $to)` and how many of that day's bookings are
     * cancelled now, zero for a day with none.
     *
     * @return array<string, int>
     */
    public function dailyCancelledCounts(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{d: string, c: int}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT DATE(created) AS d, COUNT(*) AS c FROM ' . Table::Bookings->value
            . ' WHERE created >= ? AND created < ? AND status = ? GROUP BY DATE(created)',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), BookingStatus::Cancelled->value],
        );

        /** @var array<string, int> */
        return self::continuousDays($from, $to, $rows);
    }

    /**
     * Every day in `[$from, $to)` and that day's gross booked value, zero
     * for a day with none.
     *
     * @return array<string, float>
     */
    public function dailyCostSums(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{d: string, c: string}> $rows */
        $rows = $this->connection->fetchAll(
            'SELECT DATE(created) AS d, SUM(price_base + price_tax) AS c FROM ' . Table::Bookings->value
            . ' WHERE created >= ? AND created < ? GROUP BY DATE(created)',
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );

        /** @var array<string, float> */
        return self::continuousDays($from, $to, $rows, cast: static fn(int|string $value): float => (float) $value);
    }

    /**
     * One `date => value` entry per day between two dates, filling in zero
     * for a day the grouped query above did not return a row for at all --
     * the shared shaping step behind {@see dailyMadeCounts()},
     * {@see dailyCancelledCounts()} and {@see dailyCostSums()}.
     *
     * @param list<array{d: string, c: int|string}> $rows
     * @param (callable(int|string): (int|float))|null $cast
     * @return array<string, int|float>
     */
    private static function continuousDays(DateTimeImmutable $from, DateTimeImmutable $to, array $rows, ?callable $cast = null): array
    {
        $cast ??= static fn(int|string $value): int => (int) $value;

        $byDate = [];

        foreach ($rows as $row) {
            $byDate[$row['d']] = $cast($row['c']);
        }

        $series = [];

        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $series[$date] = $byDate[$date] ?? 0;
        }

        return $series;
    }
}
