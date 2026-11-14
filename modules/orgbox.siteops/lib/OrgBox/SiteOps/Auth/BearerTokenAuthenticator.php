<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Auth;

use OrgBox\SiteOps\Config\Settings;

/**
 * Проверка токена доступа MCP. Токен берётся из заголовка Authorization: Bearer ...
 * или, если веб-сервер не передаёт Authorization в PHP, из X-SiteOps-Token.
 */
final class BearerTokenAuthenticator
{
    public function __construct(private Settings $settings)
    {
    }

    public function assertValid(array $server): void
    {
        $expectedHash = $this->settings->tokenHash();
        if ($expectedHash === '') {
            throw new AuthException('Токен доступа не создан.');
        }

        $token = self::extractToken($server);
        if ($token === '' || !hash_equals($expectedHash, hash('sha256', $token))) {
            throw new AuthException('Неверный токен доступа.');
        }
    }

    public static function extractToken(array $server): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $header = (string) ($server[$key] ?? '');
            if (preg_match('/^Bearer\s+(\S+)$/i', $header, $match)) {
                return $match[1];
            }
        }

        return trim((string) ($server['HTTP_X_SITEOPS_TOKEN'] ?? ''));
    }
}
