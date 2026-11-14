<?php

declare(strict_types=1);

namespace Tests\Support;

use OrgBox\SiteOps\Http\CounterStore;

final class MemoryCounter implements CounterStore
{
    /** @var array<string,int> */
    private array $counts = [];

    public function increment(string $key, int $ttlSeconds): int
    {
        return $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }
}
