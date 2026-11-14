<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Auth;

use OrgBox\SiteOps\Config\Settings;

/** Подписанные запросы старого API (совместимо с orgbox.siteops 0.1). */
final class HmacAuthenticator
{
    private const MAX_CLOCK_SKEW = 300;

    public function __construct(private Settings $settings, private NonceStore $nonces, private ?int $now = null)
    {
    }

    public function assertValid(string $method, string $uri, string $body, array $server): void
    {
        if (!$this->settings->apiEnabled()) {
            throw new AuthException('API отключён.', 403);
        }

        $keyId = (string) ($server['HTTP_X_ORGBOX_KEY'] ?? '');
        $timestamp = (int) ($server['HTTP_X_ORGBOX_TIMESTAMP'] ?? 0);
        $nonce = (string) ($server['HTTP_X_ORGBOX_NONCE'] ?? '');
        $signature = (string) ($server['HTTP_X_ORGBOX_SIGNATURE'] ?? '');
        $secret = $this->settings->apiSecret();
        $now = $this->now ?? time();

        if ($secret === '' || !hash_equals($this->settings->apiKeyId(), $keyId)) {
            throw new AuthException('Неверный ключ API.');
        }
        if ($timestamp < $now - self::MAX_CLOCK_SKEW || $timestamp > $now + self::MAX_CLOCK_SKEW) {
            throw new AuthException('Недопустимое время запроса.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            throw new AuthException('Недопустимый одноразовый идентификатор.');
        }

        $payload = strtoupper($method) . "\n" . $uri . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), strtolower($signature))) {
            throw new AuthException('Неверная подпись запроса.');
        }
        if (!$this->nonces->claim($keyId . ':' . $nonce, self::MAX_CLOCK_SKEW)) {
            throw new AuthException('Повторный запрос отклонён.');
        }
    }
}
