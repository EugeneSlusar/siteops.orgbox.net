<?php

declare(strict_types=1);

namespace Tests\Support;

use OrgBox\SiteOps\OAuth\OAuthStore;

final class MemoryOAuthStore implements OAuthStore
{
    /** @var array<string,array<string,mixed>> */
    public array $clients = [];
    /** @var array<string,array<string,mixed>> */
    public array $codes = [];
    /** @var array<string,array<string,mixed>> */
    public array $tokens = [];
    /** @var array<string,array<string,mixed>> */
    public array $devices = [];

    public function saveDevice(array $device): void
    {
        $this->devices[$device['device_hash']] = $device;
    }

    public function getDevice(string $deviceHash): ?array
    {
        return $this->devices[$deviceHash] ?? null;
    }

    public function getDeviceByUserCode(string $userHash): ?array
    {
        foreach ($this->devices as $device) {
            if ($device['user_hash'] === $userHash) {
                return $device;
            }
        }

        return null;
    }

    public function decideDevice(string $userHash, string $status, int $userId): bool
    {
        foreach ($this->devices as $hash => $device) {
            if ($device['user_hash'] === $userHash && $device['status'] === 'pending') {
                $this->devices[$hash]['status'] = $status;
                $this->devices[$hash]['user_id'] = $userId;

                return true;
            }
        }

        return false;
    }

    public function touchDevice(string $deviceHash, int $lastPoll, int $interval): void
    {
        if (isset($this->devices[$deviceHash])) {
            $this->devices[$deviceHash]['last_poll'] = $lastPoll;
            $this->devices[$deviceHash]['interval'] = $interval;
        }
    }

    public function consumeDevice(string $deviceHash): ?array
    {
        $device = $this->devices[$deviceHash] ?? null;
        unset($this->devices[$deviceHash]);

        return $device;
    }

    public function countDevices(int $now): int
    {
        return count(array_filter($this->devices, static fn ($d) => $d['expires'] >= $now));
    }

    public function saveClient(array $client): void
    {
        $this->clients[$client['client_id']] = $client;
    }

    public function getClient(string $clientId): ?array
    {
        return $this->clients[$clientId] ?? null;
    }

    public function countClients(): int
    {
        return count($this->clients);
    }

    public function saveCode(array $code): void
    {
        $this->codes[$code['hash']] = $code;
    }

    public function consumeCode(string $hash): ?array
    {
        $code = $this->codes[$hash] ?? null;
        unset($this->codes[$hash]);

        return $code;
    }

    public function saveToken(array $token): void
    {
        $this->tokens[$token['hash']] = $token;
    }

    public function getToken(string $hash): ?array
    {
        return $this->tokens[$hash] ?? null;
    }

    public function revokeToken(string $hash): void
    {
        if (isset($this->tokens[$hash])) {
            $this->tokens[$hash]['revoked'] = true;
        }
    }

    public function revokeFamily(string $family): void
    {
        foreach ($this->tokens as &$token) {
            if ($token['family'] === $family) {
                $token['revoked'] = true;
            }
        }
    }

    public function revokeClient(string $clientId): void
    {
        foreach ($this->tokens as &$token) {
            if ($token['client_id'] === $clientId) {
                $token['revoked'] = true;
            }
        }
        unset($this->clients[$clientId]);
    }

    public function listConnections(int $now): array
    {
        $result = [];
        foreach ($this->clients as $client) {
            $live = array_filter($this->tokens, static fn ($t) => $t['client_id'] === $client['client_id'] && $t['type'] === 'refresh' && !$t['revoked'] && $t['expires'] > $now);
            if ($live !== []) {
                $result[] = $client + ['last_used' => max(array_column($this->tokens, 'created'))];
            }
        }

        return $result;
    }

    public function purge(int $now, int $unusedClientTtl): void
    {
        foreach ($this->codes as $hash => $code) {
            if ($code['expires'] < $now) {
                unset($this->codes[$hash]);
            }
        }
        foreach ($this->devices as $hash => $device) {
            if ($device['expires'] < $now) {
                unset($this->devices[$hash]);
            }
        }
        foreach ($this->tokens as $hash => $token) {
            if ($token['expires'] < $now) {
                unset($this->tokens[$hash]);
            }
        }
        foreach ($this->clients as $id => $client) {
            $hasTokens = array_filter($this->tokens, static fn ($t) => $t['client_id'] === $id) !== [];
            if (!$hasTokens && $client['created'] < $now - $unusedClientTtl) {
                unset($this->clients[$id]);
            }
        }
    }
}
