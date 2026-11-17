<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\OAuth;

/**
 * Хранилище OAuth. Все значения секретов (коды, токены) хранятся только в виде SHA-256.
 * Реализация для Битрикса — BitrixOAuthStore (таблицы в базе, с разделением по сайтам).
 *
 * client: client_id, name, redirect_uris (list<string>), created
 * code:   hash, client_id, redirect_uri, challenge, user_id, resource, expires
 * device: device_hash, user_hash, client_id, status (pending|approved|denied), user_id, expires, interval, last_poll, ip, created
 * token:  hash, type (access|refresh), client_id, user_id, family, expires, revoked (bool), created
 */
interface OAuthStore
{
    /** @param array<string,mixed> $client */
    public function saveClient(array $client): void;

    /** @return array<string,mixed>|null */
    public function getClient(string $clientId): ?array;

    public function countClients(): int;

    /** @param array<string,mixed> $code */
    public function saveCode(array $code): void;

    /** Одноразово: возвращает запись и удаляет её; повторный вызов возвращает null. @return array<string,mixed>|null */
    public function consumeCode(string $hash): ?array;

    /** @param array<string,mixed> $device запрос по потоку устройства (RFC 8628) */
    public function saveDevice(array $device): void;

    /** @return array<string,mixed>|null */
    public function getDevice(string $deviceHash): ?array;

    /** @return array<string,mixed>|null */
    public function getDeviceByUserCode(string $userHash): ?array;

    /** Решение администратора. Меняет только запись в состоянии pending; возвращает, удалось ли. */
    public function decideDevice(string $userHash, string $status, int $userId): bool;

    public function touchDevice(string $deviceHash, int $lastPoll, int $interval): void;

    /** Одноразово: возвращает запись и удаляет её; повторный вызов возвращает null. @return array<string,mixed>|null */
    public function consumeDevice(string $deviceHash): ?array;

    public function countDevices(int $now): int;

    /** @param array<string,mixed> $token */
    public function saveToken(array $token): void;

    /** @return array<string,mixed>|null */
    public function getToken(string $hash): ?array;

    public function revokeToken(string $hash): void;

    public function revokeFamily(string $family): void;

    /** Удаляет клиента и отзывает все его токены. */
    public function revokeClient(string $clientId): void;

    /** @return list<array{client_id:string,name:string,redirect_uris:list<string>,created:int,last_used:int}> клиенты с живыми токенами */
    public function listConnections(int $now): array;

    /** Удаляет просроченные коды и токены и клиентов без токенов старше $unusedClientTtl секунд. */
    public function purge(int $now, int $unusedClientTtl): void;
}
