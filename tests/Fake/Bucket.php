<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Fake;

use TripBuilder\Aws\ObjectStore;

/**
 * A bucket that remembers, so a test can ask what reached it.
 *
 * Shared by the uploader and the sweep, which care about opposite halves of
 * the same question: what was sent, and what was removed.
 */
final class Bucket implements ObjectStore
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $cacheControl = [];

    public int $puts = 0;

    public function has(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function put(
        string $key,
        string $contents,
        string $contentType,
        string $cacheControl = ObjectStore::IMMUTABLE,
    ): void {
        $this->objects[$key] = $contents;
        $this->cacheControl[] = $cacheControl;
        $this->puts++;
    }

    /** @return list<string> */
    public function keysUnder(string $prefix): array
    {
        return array_values(array_filter(
            array_keys($this->objects),
            static fn(string $key): bool => str_starts_with($key, $prefix),
        ));
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}
