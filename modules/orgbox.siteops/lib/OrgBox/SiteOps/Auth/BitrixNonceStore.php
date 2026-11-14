<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Auth;

use Bitrix\Main\Data\Cache;

final class BitrixNonceStore implements NonceStore
{
    public function claim(string $id, int $ttlSeconds): bool
    {
        $cache = Cache::createInstance();
        $cacheId = 'orgbox.siteops.nonce.' . hash('sha256', $id);
        if ($cache->initCache($ttlSeconds, $cacheId, '/orgbox/siteops/nonces')) {
            return false;
        }
        if ($cache->startDataCache()) {
            $cache->endDataCache(['used_at' => time()]);
        }

        return true;
    }
}
