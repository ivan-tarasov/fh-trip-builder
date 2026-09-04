<?php

declare(strict_types=1);

namespace TripBuilder\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\View\StoredItinerary;

/**
 * A booking as an iCalendar file, one event per flight.
 *
 * Built here rather than in the browser because a booking stores its times as
 * local wall clock with no offset and no zone -- 22:54 at the departure airport
 * -- and a calendar file that does not say which zone that is lands hours out
 * for anyone reading it from somewhere else. The zone comes from the airports
 * table, which is reference data about a place rather than anything about the
 * price, so reading it does not thaw the snapshot the booking froze.
 */
final readonly class Calendar
{
    private const string PRODUCT_ID = '-//Trip Builder//Bookings//EN';

    public function __construct(private Connection $connection) {}

    /**
     * Null when the stored flights will not rebuild.
     *
     * @param array<string, mixed> $row a bookings row
     */
    public function forBooking(array $row): ?string
    {
        $outbound = StoredItinerary::fromJson($row['flight_outbound'] ?? null);

        if ($outbound === null) {
            return null;
        }

        $return = StoredItinerary::fromJson($row['flight_return'] ?? null);
        $segments = [...$outbound->segments, ...($return->segments ?? [])];
        $reference = trim((string) ($row['reference'] ?? ''));
        $zones = $this->timezones($segments);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODUCT_ID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($segments as $i => $segment) {
            $lines = [...$lines, ...$this->event($segment, $zones, (int) $row['id'], $i, $reference)];
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545 wants CRLF, and a trailing one.
        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    /**
     * Wrap a line to 75 octets, continuing with a leading space.
     *
     * An airport name and a reference push a DESCRIPTION well past the limit.
     * Most readers cope, but the ones that do not fail by showing the overflow
     * as a separate broken property rather than by complaining.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = substr($line, 0, 75);

        foreach (str_split(substr($line, 75), 74) as $chunk) {
            $folded .= "\r\n " . $chunk;
        }

        return $folded;
    }

    /**
     * @param array<string, string> $zones
     * @return list<string>
     */
    private function event(object $segment, array $zones, int $bookingId, int $index, string $reference): array
    {
        $from = (string) ($segment->depart->airport_code ?? '');
        $to = (string) ($segment->arrive->airport_code ?? '');
        $number = str_replace('-', ' ', (string) ($segment->number ?? ''));

        $description = array_filter([
            (string) ($segment->carrier_name ?? ''),
            $segment->depart->airport_name ?? null,
            $segment->arrive->airport_name ?? null,
            $reference === '' ? null : 'Booking reference ' . $reference,
        ]);

        return [
            'BEGIN:VEVENT',
            // Stable across downloads, so re-importing updates the event in
            // place instead of adding a second copy of the same flight.
            sprintf('UID:booking-%d-leg-%d@trip-builder', $bookingId, $index),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            $this->stamp('DTSTART', (string) $segment->depart->date_time, $zones[$from] ?? null),
            $this->stamp('DTEND', (string) $segment->arrive->date_time, $zones[$to] ?? null),
            'SUMMARY:' . $this->escape(trim($number . ' ' . $from . ' to ' . $to)),
            'LOCATION:' . $this->escape($from),
            'DESCRIPTION:' . $this->escape(implode("\n", $description)),
            'END:VEVENT',
        ];
    }

    /**
     * One DTSTART/DTEND line.
     *
     * With a known zone the stamp is written in UTC, which every calendar reads
     * the same way. Without one it is written floating -- no zone, no Z -- which
     * a calendar shows at that wall-clock time wherever it is opened. That is
     * the honest reading of a stamp whose zone nobody recorded.
     */
    private function stamp(string $field, string $dateTime, ?string $zone): string
    {
        if ($zone === null) {
            return $field . ':' . date('Ymd\THis', strtotime($dateTime));
        }

        try {
            return $field . ':' . new DateTimeImmutable($dateTime, new DateTimeZone($zone))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Ymd\THis\Z');
        } catch (Throwable) {
            return $field . ':' . date('Ymd\THis', strtotime($dateTime));
        }
    }

    /**
     * IANA zone per airport code, for the codes this booking touches.
     *
     * @param list<object> $segments
     * @return array<string, string>
     */
    private function timezones(array $segments): array
    {
        $codes = [];

        foreach ($segments as $segment) {
            $codes[(string) ($segment->depart->airport_code ?? '')] = true;
            $codes[(string) ($segment->arrive->airport_code ?? '')] = true;
        }

        $codes = array_values(array_filter(array_keys($codes)));

        if ($codes === []) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT code, timezone_name FROM ' . Table::Airports->value
            . ' WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')',
            $codes,
        );

        $zones = [];

        foreach ($rows as $row) {
            if (trim((string) $row['timezone_name']) !== '') {
                $zones[(string) $row['code']] = (string) $row['timezone_name'];
            }
        }

        return $zones;
    }

    /**
     * RFC 5545 text escaping: backslash, semicolon, comma and newline.
     */
    private function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\;', '\\,', '\\n'],
            $text,
        );
    }
}
