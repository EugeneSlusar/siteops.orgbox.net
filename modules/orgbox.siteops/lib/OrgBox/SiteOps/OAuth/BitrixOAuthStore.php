<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\OAuth;

use Bitrix\Main\Application;

/**
 * OAuth-хранилище в базе Битрикса. Таблицы создаются при первом обращении (CREATE TABLE IF NOT EXISTS).
 * Все записи привязаны к сайту ($siteId): код и токен, выданные на одном сайте, на другом не действуют,
 * даже если сайты используют одну базу.
 */
final class BitrixOAuthStore implements OAuthStore
{
    private const T_CLIENT = 'orgbox_siteops_oauth_client';
    private const T_CODE = 'orgbox_siteops_oauth_code';
    private const T_TOKEN = 'orgbox_siteops_oauth_token';

    private static bool $schemaReady = false;

    private \Bitrix\Main\DB\Connection $db;
    private \Bitrix\Main\DB\SqlHelper $sql;

    public function __construct(private string $siteId)
    {
        $this->db = Application::getConnection();
        $this->sql = $this->db->getSqlHelper();
        $this->ensureSchema();
    }

    public function saveClient(array $client): void
    {
        $this->db->queryExecute(sprintf(
            'INSERT INTO %s (site, client_id, name, redirect_uris, created) VALUES (%s, %s, %s, %s, %d)',
            self::T_CLIENT, $this->q($this->siteId), $this->q($client['client_id']), $this->q($client['name']),
            $this->q(json_encode($client['redirect_uris'], JSON_UNESCAPED_SLASHES)), (int) $client['created'],
        ));
    }

    public function getClient(string $clientId): ?array
    {
        $row = $this->db->query(sprintf(
            'SELECT client_id, name, redirect_uris, created FROM %s WHERE site = %s AND client_id = %s',
            self::T_CLIENT, $this->q($this->siteId), $this->q($clientId),
        ))->fetch();

        return $row ? $this->clientRow($row) : null;
    }

    public function countClients(): int
    {
        $row = $this->db->query(sprintf('SELECT COUNT(*) AS c FROM %s WHERE site = %s', self::T_CLIENT, $this->q($this->siteId)))->fetch();

        return (int) ($row['c'] ?? 0);
    }

    public function saveCode(array $code): void
    {
        $this->db->queryExecute(sprintf(
            'INSERT INTO %s (site, code_hash, client_id, redirect_uri, challenge, user_id, resource, expires) VALUES (%s, %s, %s, %s, %s, %d, %s, %d)',
            self::T_CODE, $this->q($this->siteId), $this->q($code['hash']), $this->q($code['client_id']), $this->q($code['redirect_uri']),
            $this->q($code['challenge']), (int) $code['user_id'], $this->q($code['resource']), (int) $code['expires'],
        ));
    }

    public function consumeCode(string $hash): ?array
    {
        $where = sprintf('site = %s AND code_hash = %s', $this->q($this->siteId), $this->q($hash));
        $row = $this->db->query('SELECT client_id, redirect_uri, challenge, user_id, resource, expires FROM ' . self::T_CODE . ' WHERE ' . $where)->fetch();
        if (!$row) {
            return null;
        }
        // Удаляем и проверяем, что именно мы забрали код: при гонке двух запросов один из них получит 0 строк.
        $this->db->queryExecute('DELETE FROM ' . self::T_CODE . ' WHERE ' . $where);
        if ($this->db->getAffectedRowsCount() !== 1) {
            return null;
        }

        return ['hash' => $hash, 'client_id' => (string) $row['client_id'], 'redirect_uri' => (string) $row['redirect_uri'],
            'challenge' => (string) $row['challenge'], 'user_id' => (int) $row['user_id'], 'resource' => (string) $row['resource'], 'expires' => (int) $row['expires']];
    }

    public function saveToken(array $token): void
    {
        $this->db->queryExecute(sprintf(
            'INSERT INTO %s (site, token_hash, type, client_id, user_id, family, expires, revoked, created) VALUES (%s, %s, %s, %s, %d, %s, %d, %d, %d)',
            self::T_TOKEN, $this->q($this->siteId), $this->q($token['hash']), $this->q($token['type']), $this->q($token['client_id']),
            (int) $token['user_id'], $this->q($token['family']), (int) $token['expires'], $token['revoked'] ? 1 : 0, (int) $token['created'],
        ));
    }

    public function getToken(string $hash): ?array
    {
        $row = $this->db->query(sprintf(
            'SELECT token_hash, type, client_id, user_id, family, expires, revoked, created FROM %s WHERE site = %s AND token_hash = %s',
            self::T_TOKEN, $this->q($this->siteId), $this->q($hash),
        ))->fetch();

        return $row ? [
            'hash' => (string) $row['token_hash'], 'type' => (string) $row['type'], 'client_id' => (string) $row['client_id'],
            'user_id' => (int) $row['user_id'], 'family' => (string) $row['family'], 'expires' => (int) $row['expires'],
            'revoked' => (int) $row['revoked'] === 1, 'created' => (int) $row['created'],
        ] : null;
    }

    public function revokeToken(string $hash): void
    {
        $this->db->queryExecute(sprintf('UPDATE %s SET revoked = 1 WHERE site = %s AND token_hash = %s', self::T_TOKEN, $this->q($this->siteId), $this->q($hash)));
    }

    public function revokeFamily(string $family): void
    {
        $this->db->queryExecute(sprintf('UPDATE %s SET revoked = 1 WHERE site = %s AND family = %s', self::T_TOKEN, $this->q($this->siteId), $this->q($family)));
    }

    public function revokeClient(string $clientId): void
    {
        $site = $this->q($this->siteId);
        $client = $this->q($clientId);
        $this->db->queryExecute(sprintf('UPDATE %s SET revoked = 1 WHERE site = %s AND client_id = %s', self::T_TOKEN, $site, $client));
        $this->db->queryExecute(sprintf('DELETE FROM %s WHERE site = %s AND client_id = %s', self::T_CODE, $site, $client));
        $this->db->queryExecute(sprintf('DELETE FROM %s WHERE site = %s AND client_id = %s', self::T_CLIENT, $site, $client));
    }

    public function listConnections(int $now): array
    {
        $site = $this->q($this->siteId);
        $live = sprintf("type = 'refresh' AND revoked = 0 AND expires > %d AND site = %s", $now, $site);
        $rows = $this->db->query(sprintf(
            'SELECT c.client_id, c.name, c.redirect_uris, c.created,
                    (SELECT MAX(t.created) FROM %1$s t WHERE t.site = c.site AND t.client_id = c.client_id) AS last_used
               FROM %2$s c
              WHERE c.site = %3$s AND c.client_id IN (SELECT client_id FROM %1$s WHERE %4$s)
              ORDER BY c.created DESC',
            self::T_TOKEN, self::T_CLIENT, $site, $live,
        ));
        $result = [];
        while ($row = $rows->fetch()) {
            $result[] = $this->clientRow($row) + ['last_used' => (int) $row['last_used']];
        }

        return $result;
    }

    public function purge(int $now, int $unusedClientTtl): void
    {
        $site = $this->q($this->siteId);
        $this->db->queryExecute(sprintf('DELETE FROM %s WHERE site = %s AND expires < %d', self::T_CODE, $site, $now));
        $this->db->queryExecute(sprintf('DELETE FROM %s WHERE site = %s AND expires < %d', self::T_TOKEN, $site, $now));
        $this->db->queryExecute(sprintf(
            'DELETE FROM %1$s WHERE site = %2$s AND created < %3$d AND client_id NOT IN (SELECT client_id FROM %4$s WHERE site = %2$s)',
            self::T_CLIENT, $site, $now - $unusedClientTtl, self::T_TOKEN,
        ));
    }

    /** @param array<string,mixed> $row @return array{client_id:string,name:string,redirect_uris:list<string>,created:int} */
    private function clientRow(array $row): array
    {
        $uris = json_decode((string) $row['redirect_uris'], true);

        return ['client_id' => (string) $row['client_id'], 'name' => (string) $row['name'],
            'redirect_uris' => is_array($uris) ? array_values(array_map('strval', $uris)) : [], 'created' => (int) $row['created']];
    }

    private function q(string $value): string
    {
        return "'" . $this->sql->forSql($value) . "'";
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $this->db->queryExecute('CREATE TABLE IF NOT EXISTS ' . self::T_CLIENT . ' (
            site VARCHAR(8) NOT NULL, client_id VARCHAR(40) NOT NULL, name VARCHAR(255) NOT NULL,
            redirect_uris TEXT NOT NULL, created INT NOT NULL, PRIMARY KEY (site, client_id))');
        $this->db->queryExecute('CREATE TABLE IF NOT EXISTS ' . self::T_CODE . ' (
            site VARCHAR(8) NOT NULL, code_hash CHAR(64) NOT NULL, client_id VARCHAR(40) NOT NULL, redirect_uri VARCHAR(2000) NOT NULL,
            challenge VARCHAR(128) NOT NULL, user_id INT NOT NULL, resource VARCHAR(500) NOT NULL, expires INT NOT NULL,
            PRIMARY KEY (site, code_hash))');
        $this->db->queryExecute('CREATE TABLE IF NOT EXISTS ' . self::T_TOKEN . ' (
            site VARCHAR(8) NOT NULL, token_hash CHAR(64) NOT NULL, type VARCHAR(10) NOT NULL, client_id VARCHAR(40) NOT NULL,
            user_id INT NOT NULL, family VARCHAR(40) NOT NULL, expires INT NOT NULL, revoked TINYINT NOT NULL DEFAULT 0, created INT NOT NULL,
            PRIMARY KEY (site, token_hash), KEY ix_family (site, family), KEY ix_client (site, client_id))');
        self::$schemaReady = true;
    }
}
