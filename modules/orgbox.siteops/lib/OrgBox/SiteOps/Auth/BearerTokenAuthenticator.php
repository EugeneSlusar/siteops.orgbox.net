<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Auth;

use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\OAuth\OAuthServer;

/**
 * Проверка токена доступа MCP. Токен берётся из заголовка Authorization: Bearer ...
 * или, если веб-сервер не передаёт Authorization в PHP, из X-SiteOps-Token.
 */
final class BearerTokenAuthenticator
{
    public function __construct(private Settings $settings, private ?OAuthServer $oauth = null)
    {
    }

    /** Принимается либо статический токен из настроек, либо OAuth-токен доступа (если OAuth включён). */
    public function assertValid(array $server): void
    {
        $token = self::extractToken($server);
        if ($token === '') {
            throw new AuthException('Нужен токен доступа.');
        }

        $expectedHash = $this->settings->tokenHash();
        if ($expectedHash !== '' && hash_equals($expectedHash, hash('sha256', $token))) {
            return;
        }
        if ($this->oauth !== null && $this->settings->oauthEnabled() && $this->oauth->authenticateAccessToken($token) !== null) {
            return;
        }

        throw new AuthException('Неверный или просроченный токен доступа.');
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
