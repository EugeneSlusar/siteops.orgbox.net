<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

interface CounterStore
{
    /** Увеличивает счётчик ключа и возвращает новое значение. Ключ живёт $ttlSeconds. */
    public function increment(string $key, int $ttlSeconds): int;
}
