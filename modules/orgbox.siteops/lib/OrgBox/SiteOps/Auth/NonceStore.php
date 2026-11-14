<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Auth;

interface NonceStore
{
    /** Возвращает false, если одноразовый идентификатор уже использовался. */
    public function claim(string $id, int $ttlSeconds): bool;
}
