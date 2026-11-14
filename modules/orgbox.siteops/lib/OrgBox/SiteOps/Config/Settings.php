<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Config;

/** Типизированный доступ к настройкам. Секреты в открытом виде не хранятся, кроме HMAC-секрета старого API. */
final class Settings
{
    public const MODE_READONLY = 'readonly';
    public const MODE_READWRITE = 'readwrite';

    public function __construct(private OptionStore $store)
    {
    }

    public function mcpEnabled(): bool
    {
        return $this->store->get('mcp_enabled', 'N') === 'Y';
    }

    public function apiEnabled(): bool
    {
        return $this->store->get('api_enabled', 'N') === 'Y';
    }

    public function mode(): string
    {
        return $this->store->get('mode', self::MODE_READONLY) === self::MODE_READWRITE
            ? self::MODE_READWRITE
            : self::MODE_READONLY;
    }

    public function writesEnabled(): bool
    {
        return $this->mode() === self::MODE_READWRITE;
    }

    /** Возвращает токен один раз; в настройках остаётся только SHA-256. */
    public function generateToken(): string
    {
        $token = 'sop_' . bin2hex(random_bytes(24));
        $this->store->set('token_hash', hash('sha256', $token));

        return $token;
    }

    public function revokeToken(): void
    {
        $this->store->set('token_hash', '');
    }

    public function tokenHash(): string
    {
        return $this->store->get('token_hash', '');
    }

    /** @return int[] пусто = все инфоблоки доступны для чтения; запись требует явного списка */
    public function allowedIblocks(): array
    {
        return self::intList($this->store->get('allowed_iblocks', ''));
    }

    /** @return string[] */
    public function allowedIps(): array
    {
        return self::stringList($this->store->get('allowed_ips', ''));
    }

    /** @return string[] */
    public function allowedOrigins(): array
    {
        return self::stringList($this->store->get('allowed_origins', ''));
    }

    public function rateLimitPerMinute(): int
    {
        return max(1, min(1000, (int) $this->store->get('rate_limit', '60')));
    }

    public function defaultIblockId(): int
    {
        return max(0, (int) $this->store->get('default_iblock_id', '0'));
    }

    public function apiKeyId(): string
    {
        return $this->store->get('api_key_id', 'siteops');
    }

    public function apiSecret(): string
    {
        return $this->store->get('api_secret', '');
    }

    public function updateRepository(): string
    {
        return $this->store->get('update_repo', 'EugeneSlusar/siteops.orgbox.net');
    }

    public function updateGithubToken(): string
    {
        return $this->store->get('update_github_token', '');
    }

    /** Открытый ключ Ed25519 (base64), которым подписываются релизы. */
    public function updatePublicKey(): string
    {
        return $this->store->get('update_public_key', '');
    }

    /** Разрешить установку обновления без подписи (только SHA-256). По умолчанию запрещено. */
    public function allowUnsignedUpdates(): bool
    {
        return $this->store->get('update_allow_unsigned', 'N') === 'Y';
    }

    public function store(): OptionStore
    {
        return $this->store;
    }

    /** @return int[] */
    public static function intList(string $value): array
    {
        $result = [];
        foreach (preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (ctype_digit($part) && (int) $part > 0) {
                $result[] = (int) $part;
            }
        }

        return array_values(array_unique($result));
    }

    /** @return string[] */
    public static function stringList(string $value): array
    {
        return array_values(array_unique(preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }
}
