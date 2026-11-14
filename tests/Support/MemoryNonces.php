<?php

declare(strict_types=1);

namespace Tests\Support;

use OrgBox\SiteOps\Auth\NonceStore;

final class MemoryNonces implements NonceStore
{
    /** @var array<string,true> */
    private array $used = [];

    public function claim(string $id, int $ttlSeconds): bool
    {
        if (isset($this->used[$id])) {
            return false;
        }
        $this->used[$id] = true;

        return true;
    }
}
