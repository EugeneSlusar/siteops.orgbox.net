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
