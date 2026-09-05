<?php

declare(strict_types=1);

namespace TripBuilder\Http;

/**
 * One bag of request values -- a query string, a form body, the cookies --
 * read through accessors that always return the type they promise.
 *
 * Values are typed, not sanitised. Nothing here strips or escapes anything,
 * because escaping belongs at the point of use and already happens there: Twig
 * renders with autoescape, and every query is a prepared statement with bound
 * parameters. Escaping on the way in would only corrupt what people legitimately
 * type -- an apostrophe in a surname, an accent in a city -- while leaving the
 * output boundaries doing the work regardless.
 *
 * What it does do is settle presence, type and default in one place. The same
 * three concerns were being spelled a different way at every call site, and the
 * inconsistency was the actual bug surface: `$_GET['shown'] ?? 10` hands back a
 * string from the URL and an int from the default, and callers then compare the
 * two.
 *
 * Holds a plain array, so a test constructs one directly with no globals to set
 * up or tear down.
 */
final readonly class Input
{
    /** @param array<array-key, mixed> $values */
    public function __construct(private array $values = []) {}

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * A trimmed string, or the default when the key is absent or is not
     * something a string can be read from -- an array from `?a[]=1`, most
     * often, which is where an unguarded (string) cast would throw.
     */
    public function str(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * The same, but absence stays absent.
     *
     * For values whose "not given" is meaningful rather than a default -- a
     * cabin nobody chose is not an empty cabin, and the enum that reads it
     * distinguishes the two.
     */
    public function nullableStr(string $key): ?string
    {
        if (!$this->has($key)) {
            return null;
        }

        $value = $this->values[$key];

        return is_scalar($value) ? trim((string) $value) : null;
    }

    /**
     * An integer, or the default when the value is absent or is not one.
     *
     * Strict: "10abc" and "" are not integers and yield the default, where a
     * cast would have produced 10 and 0.
     */
    public function int(string $key, int $default = 0): int
    {
        $value = filter_var($this->values[$key] ?? null, FILTER_VALIDATE_INT);

        return $value === false ? $default : $value;
    }

    /**
     * An integer inside a range, or the default.
     *
     * Out of range yields the default rather than the nearest bound. That is
     * the safer reading for the value this exists for -- how many results to
     * hydrate at once -- because a URL asking for a hundred thousand rows is
     * more likely crafted than mistyped, and answering with the maximum is
     * still answering. It also matches what the filter_var call it replaced
     * did, so no request changes meaning.
     */
    public function intWithin(string $key, int $default, int $min, int $max): int
    {
        $value = filter_var(
            $this->values[$key] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $min, 'max_range' => $max]],
        );

        return $value === false ? $default : $value;
    }

    /**
     * A comma-separated list of positive integers -- the ordered leg ids an
     * itinerary is named by.
     *
     * All or nothing: one unparseable part rejects the whole list rather than
     * dropping that element. A list quietly shortened by one is an itinerary
     * missing a leg, which would price and book as a different trip than the
     * one whose link was followed.
     *
     * @return list<int>
     */
    public function ids(string $key): array
    {
        $raw = $this->values[$key] ?? null;

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $id = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false) {
                return [];
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Whether the value equals the given string exactly, for the flag-shaped
     * parameters this app uses (`fragment=1`).
     */
    public function is(string $key, string $expected): bool
    {
        return ($this->values[$key] ?? null) === $expected;
    }

    /**
     * The value as it arrived, untyped.
     *
     * For the one shape the typed accessors cannot express: a filter that is a
     * string when it comes from a shared link and an array when it comes from a
     * checkbox group. The caller has to handle both, so this says so rather
     * than pretending one of them away.
     */
    public function raw(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * A repeated group of fields, as one Input per entry.
     *
     * The checkout form posts `passengers[0][first_name]`, so every accessor
     * above -- which reads scalars -- has nothing to reach it with. Each entry
     * comes back as an Input of its own so the same validation reads a
     * passenger the way it always read the form.
     *
     * Entries arrive keyed by index; the keys are dropped so a hand-posted
     * `passengers[7]` cannot leave a hole the caller has to think about.
     *
     * @return list<self>
     */
    public function group(string $key, int $limit): array
    {
        $raw = $this->values[$key] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $entries = [];

        foreach (array_slice($raw, 0, $limit) as $entry) {
            $entries[] = new self(is_array($entry) ? $entry : []);
        }

        return $entries;
    }

    /** @return array<array-key, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
