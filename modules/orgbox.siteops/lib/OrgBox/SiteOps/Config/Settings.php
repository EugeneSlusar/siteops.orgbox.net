<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Config;

/** Типизированный доступ к настройкам. Секреты (токены, коды) в открытом виде не хранятся. */
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

    /** Подключение клиентов через OAuth (ссылка и согласие администратора). */
    public function oauthEnabled(): bool
    {
        return $this->store->get('oauth_enabled', 'N') === 'Y';
    }

    /** Адрес сайта для OAuth-документов, например https://shop.example. Пусто — берётся из запроса. */
    public function publicUrl(): string
    {
        return rtrim($this->store->get('public_url', ''), '/');
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

    /** @return int[] список инфоблоков: для них разрешена запись, а при выключенном «чтении всех» — ещё и чтение */
    public function allowedIblocks(): array
    {
        return self::intList($this->store->get('allowed_iblocks', ''));
    }

    /**
     * Читать все инфоблоки (true) или только перечисленные в списке (false).
     * Явное значение Y/N задаёт галочка в настройках. Не задано (установки до появления галочки):
     * как раньше, пустой список = читать все.
     */
    public function readAllIblocks(): bool
    {
        $value = $this->store->get('read_all_iblocks', '');
        if ($value === 'Y' || $value === 'N') {
            return $value === 'Y';
        }

        return $this->allowedIblocks() === [];
    }

    public function canReadIblock(int $id): bool
    {
        return $this->readAllIblocks() || in_array($id, $this->allowedIblocks(), true);
    }

    /** Что доступно клиенту, для страниц согласия: чтение и запись описываются отдельно. */
    public function accessSummary(): string
    {
        $list = $this->allowedIblocks();
        if ($this->readAllIblocks()) {
            return 'все инфоблоки; запись ' . ($list === [] ? 'запрещена' : 'только в ' . implode(', ', $list));
        }

        return $list === [] ? 'ни один инфоблок (список пуст)' : 'только инфоблоки ' . implode(', ', $list);
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

    /** Секрет для защиты форм от CSRF: создаётся при первом обращении и хранится рядом с остальными настройками. */
    public function csrfSecret(): string
    {
        $secret = $this->store->get('csrf_secret', '');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->store->set('csrf_secret', $secret);
        }

        return $secret;
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
