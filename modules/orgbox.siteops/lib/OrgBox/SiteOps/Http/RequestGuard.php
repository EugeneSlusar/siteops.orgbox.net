<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

use OrgBox\SiteOps\Auth\AuthException;
use OrgBox\SiteOps\Config\Settings;

/** Общие проверки входа: IP, Origin (защита от DNS rebinding) и частота запросов. */
final class RequestGuard
{
    public function __construct(private Settings $settings, private CounterStore $counters, private ?int $now = null)
    {
    }

    public function assertAllowed(array $server): void
    {
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        $allowedIps = $this->settings->allowedIps();
        if ($allowedIps !== [] && !in_array($ip, $allowedIps, true)) {
            throw new AuthException('Доступ с этого адреса запрещён.', 403);
        }

        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && !in_array($origin, $this->settings->allowedOrigins(), true)) {
            throw new AuthException('Недопустимый Origin.', 403);
        }

        $this->assertRateLimit($server);
    }

    /** Только лимит запросов (для OAuth-адресов: список IP и Origin к ним не применяются). */
    public function assertRateLimit(array $server, string $bucket = 'ip'): void
    {
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        $minute = intdiv($this->now ?? time(), 60);
        if ($this->counters->increment($bucket . ':' . $ip . ':' . $minute, 120) > $this->settings->rateLimitPerMinute()) {
            throw new AuthException('Слишком много запросов.', 429);
        }
    }
}
