<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

use Bitrix\Main\Data\Cache;

/** Приблизительный счётчик на кеше Битрикса: для защиты от перебора точности атомарности не требуется. */
final class BitrixCounterStore implements CounterStore
{
    public function increment(string $key, int $ttlSeconds): int
    {
        $cache = Cache::createInstance();
        $cacheId = 'orgbox.siteops.rate.' . hash('sha256', $key);
        $count = 0;
        if ($cache->initCache($ttlSeconds, $cacheId, '/orgbox/siteops/rate')) {
            $count = (int) ($cache->getVars()['count'] ?? 0);
        }
        $count++;
        $cache->forceRewriting(true);
        if ($cache->startDataCache($ttlSeconds, $cacheId, '/orgbox/siteops/rate')) {
            $cache->endDataCache(['count' => $count]);
        }

        return $count;
    }
}
