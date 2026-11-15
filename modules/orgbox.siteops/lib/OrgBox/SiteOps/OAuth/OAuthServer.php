<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\OAuth;

/**
 * OAuth 2.1 для MCP: код авторизации + PKCE (только S256), динамическая регистрация клиентов (RFC 7591),
 * обновляемые токены с ротацией и обнаружением повторного использования, отзыв (RFC 7009).
 * Клиенты публичные (без секрета). Права доступа токена не шире настроек сайта: режим и список
 * инфоблоков проверяются при каждом вызове инструмента, а не записываются в токен.
 */
final class OAuthServer
{
    public const CODE_TTL = 600;
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 2592000; // 30 суток
    public const SCOPE = 'siteops';
    private const MAX_CLIENTS = 200;
    private const MAX_REDIRECTS = 5;
    private const UNUSED_CLIENT_TTL = 604800; // 7 суток

    /** @var (callable():int)|null */
    private $clock;

    /**
     * @param string $baseUrl адрес сайта без слэша на конце, например https://shop.example
     * @param (callable():int)|null $clock
     */
    public function __construct(private OAuthStore $store, private string $baseUrl, ?callable $clock = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->clock = $clock;
    }

    public function issuer(): string
    {
        return $this->baseUrl . '/site.ops/oauth';
    }

    public function resource(): string
    {
        return $this->baseUrl . '/site.ops/mcp';
    }

    public function resourceMetadataUrl(): string
    {
        return $this->issuer() . '/.well-known/oauth-protected-resource/';
    }

    /** @return array<string,mixed> */
    public function authorizationServerMetadata(): array
    {
        return [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $this->issuer() . '/authorize/',
            'token_endpoint' => $this->issuer() . '/token/',
            'registration_endpoint' => $this->issuer() . '/register/',
            'revocation_endpoint' => $this->issuer() . '/revoke/',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [self::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [self::SCOPE],
            'resource_name' => 'orgBox SiteOps MCP',
        ];
    }

    // ---- регистрация клиента (RFC 7591) -----------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function register(array $payload): array
    {
        $now = $this->now();
        $this->store->purge($now, self::UNUSED_CLIENT_TTL);
        if ($this->store->countClients() >= self::MAX_CLIENTS) {
            throw new OAuthException('temporarily_unavailable', 'Слишком много зарегистрированных клиентов. Повторите позже.', 503);
        }

        $uris = $payload['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || count($uris) > self::MAX_REDIRECTS) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris: нужен список от 1 до ' . self::MAX_REDIRECTS . ' адресов.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::isAllowedRedirectUri($uri)) {
                throw new OAuthException('invalid_redirect_uri', 'Недопустимый redirect_uri: разрешены https:// и http://localhost (127.0.0.1), без фрагмента и логина.');
            }
        }

        $method = $payload['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            throw new OAuthException('invalid_client_metadata', 'Поддерживается только token_endpoint_auth_method = none (публичный клиент с PKCE).');
        }
        $grants = $payload['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token']) !== []) {
            throw new OAuthException('invalid_client_metadata', 'Поддерживаются grant_types: authorization_code, refresh_token.');
        }

        $name = $payload['client_name'] ?? 'Клиент MCP';
        $name = is_string($name) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '') : '';
        $name = mb_substr($name !== '' ? $name : 'Клиент MCP', 0, 100);

        $clientId = 'sopc_' . bin2hex(random_bytes(16));
        $this->store->saveClient(['client_id' => $clientId, 'name' => $name, 'redirect_uris' => array_values($uris), 'created' => $now]);

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => $now,
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => self::SCOPE,
        ];
    }

    public static function isAllowedRedirectUri(string $uri): bool
    {
        if (strlen($uri) > 2000 || preg_match('/[\x00-\x20\x7F]/', $uri)) {
            return false;
        }
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (str_contains($uri, '#')) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    // ---- авторизация --------------------------------------------------------------------------------

    /**
     * Проверяет запрос на странице согласия.
     * @param array<string,mixed> $query
     * @return array{client:array<string,mixed>,redirect_uri:string,state:?string,challenge:string,resource:string}
     * @throws AuthorizeException
     */
    public function validateAuthorizationRequest(array $query): array
    {
        $clientId = self::str($query, 'client_id');
        $client = $clientId !== '' ? $this->store->getClient($clientId) : null;
        if ($client === null) {
            throw new AuthorizeException('invalid_request', 'Неизвестный клиент. Подключение нужно начать заново из клиента MCP.');
        }
        $redirect = self::str($query, 'redirect_uri');
        if ($redirect === '' && count($client['redirect_uris']) === 1) {
            $redirect = $client['redirect_uris'][0];
        }
        // Точное совпадение с зарегистрированным адресом: иначе открытый редирект и кража кода.
        if (!in_array($redirect, $client['redirect_uris'], true)) {
            throw new AuthorizeException('invalid_request', 'redirect_uri не совпадает с зарегистрированным для клиента.');
        }
        $state = self::str($query, 'state') !== '' ? mb_substr(self::str($query, 'state'), 0, 512) : null;

        if (($query['response_type'] ?? '') !== 'code') {
            throw new AuthorizeException('unsupported_response_type', 'Поддерживается только response_type=code.', $redirect, $state);
        }
        $challenge = self::str($query, 'code_challenge');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            throw new AuthorizeException('invalid_request', 'Нужен code_challenge (PKCE, S256).', $redirect, $state);
        }
        if (($query['code_challenge_method'] ?? '') !== 'S256') {
            throw new AuthorizeException('invalid_request', 'Поддерживается только code_challenge_method=S256.', $redirect, $state);
        }
        $resource = self::str($query, 'resource');
        if (isset($query['resource']) && !is_string($query['resource'])) {
            throw new AuthorizeException('invalid_target', 'resource должен быть строкой.', $redirect, $state);
        }
        if ($resource !== '' && rtrim($resource, '/') !== $this->resource()) {
            throw new AuthorizeException('invalid_target', 'resource не совпадает с адресом этого MCP-сервера.', $redirect, $state);
        }

        return ['client' => $client, 'redirect_uri' => $redirect, 'state' => $state, 'challenge' => $challenge, 'resource' => $this->resource()];
    }

    /**
     * Администратор разрешил доступ: выдаёт одноразовый код и возвращает адрес возврата.
     * @param array{client:array<string,mixed>,redirect_uri:string,state:?string,challenge:string,resource:string} $request
     */
    public function approve(array $request, int $userId): string
    {
        $code = 'sop_code_' . self::randomToken();
        $this->store->saveCode([
            'hash' => self::hash($code),
            'client_id' => $request['client']['client_id'],
            'redirect_uri' => $request['redirect_uri'],
            'challenge' => $request['challenge'],
            'user_id' => $userId,
            'resource' => $request['resource'],
            'expires' => $this->now() + self::CODE_TTL,
        ]);

        return $this->redirectWith($request['redirect_uri'], ['code' => $code, 'state' => $request['state'], 'iss' => $this->issuer()]);
    }

    /** @param array{redirect_uri:string,state:?string} $request */
    public function deny(array $request): string
    {
        return $this->redirectWith($request['redirect_uri'], ['error' => 'access_denied', 'state' => $request['state'], 'iss' => $this->issuer()]);
    }

    public function errorRedirect(AuthorizeException $exception): ?string
    {
        if ($exception->redirectUri === null) {
            return null;
        }

        return $this->redirectWith($exception->redirectUri, [
            'error' => $exception->error, 'error_description' => $exception->getMessage(), 'state' => $exception->state, 'iss' => $this->issuer(),
        ]);
    }

    // ---- токены -------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $form
     * @return array<string,mixed>
     * @throws OAuthException
     */
    public function token(array $form): array
    {
        $grant = self::str($form, 'grant_type');
        $clientId = self::str($form, 'client_id');
        $client = $clientId !== '' ? $this->store->getClient($clientId) : null;
        if ($client === null) {
            throw new OAuthException('invalid_client', 'Неизвестный клиент.', 401);
        }

        return match ($grant) {
            'authorization_code' => $this->exchangeCode($client, $form),
            'refresh_token' => $this->refresh($client, $form),
            default => throw new OAuthException('unsupported_grant_type', 'Поддерживаются authorization_code и refresh_token.'),
        };
    }

    /** @param array<string,mixed> $client @param array<string,mixed> $form @return array<string,mixed> */
    private function exchangeCode(array $client, array $form): array
    {
        $codeValue = self::str($form, 'code');
        $verifier = self::str($form, 'code_verifier');
        if ($codeValue === '' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            throw new OAuthException('invalid_request', 'Нужны code и code_verifier (43–128 символов).');
        }

        // Код одноразовый: расходуется при первой же попытке, даже неудачной.
        $code = $this->store->consumeCode(self::hash($codeValue));
        if ($code === null || $code['expires'] < $this->now() || $code['client_id'] !== $client['client_id']) {
            throw new OAuthException('invalid_grant', 'Код недействителен, просрочен или уже использован.');
        }
        if (!hash_equals((string) $code['redirect_uri'], self::str($form, 'redirect_uri'))) {
            throw new OAuthException('invalid_grant', 'redirect_uri не совпадает с использованным при авторизации.');
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $code['challenge'], $expected)) {
            throw new OAuthException('invalid_grant', 'Проверка PKCE не пройдена.');
        }
        $resource = self::str($form, 'resource');
        if (isset($form['resource']) && !is_string($form['resource'])) {
            throw new OAuthException('invalid_target', 'resource должен быть строкой.');
        }
        if ($resource !== '' && rtrim($resource, '/') !== $this->resource()) {
            throw new OAuthException('invalid_target', 'resource не совпадает с адресом этого MCP-сервера.');
        }

        return $this->issue($client['client_id'], (int) $code['user_id'], bin2hex(random_bytes(12)));
    }

    /** @param array<string,mixed> $client @param array<string,mixed> $form @return array<string,mixed> */
    private function refresh(array $client, array $form): array
    {
        $value = self::str($form, 'refresh_token');
        $token = $value !== '' ? $this->store->getToken(self::hash($value)) : null;
        if ($token === null || $token['type'] !== 'refresh' || $token['client_id'] !== $client['client_id']) {
            throw new OAuthException('invalid_grant', 'Токен обновления недействителен.');
        }
        if ($token['revoked']) {
            // Повторное предъявление уже использованного токена: возможна кража, отзываем всю цепочку.
            $this->store->revokeFamily((string) $token['family']);
            throw new OAuthException('invalid_grant', 'Токен обновления уже использован; доступ отозван, подключитесь заново.');
        }
        if ($token['expires'] < $this->now()) {
            throw new OAuthException('invalid_grant', 'Срок токена обновления истёк, подключитесь заново.');
        }

        $this->store->revokeToken(self::hash($value));

        return $this->issue($client['client_id'], (int) $token['user_id'], (string) $token['family']);
    }

    /** @return array<string,mixed> */
    private function issue(string $clientId, int $userId, string $family): array
    {
        $now = $this->now();
        $access = 'sop_at_' . self::randomToken();
        $refresh = 'sop_rt_' . self::randomToken();
        $this->store->saveToken(['hash' => self::hash($access), 'type' => 'access', 'client_id' => $clientId, 'user_id' => $userId, 'family' => $family, 'expires' => $now + self::ACCESS_TTL, 'revoked' => false, 'created' => $now]);
        $this->store->saveToken(['hash' => self::hash($refresh), 'type' => 'refresh', 'client_id' => $clientId, 'user_id' => $userId, 'family' => $family, 'expires' => $now + self::REFRESH_TTL, 'revoked' => false, 'created' => $now]);

        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => self::SCOPE];
    }

    /** RFC 7009: всегда без ошибки, чтобы не раскрывать существование токена. */
    public function revoke(string $tokenValue): void
    {
        $token = $tokenValue !== '' ? $this->store->getToken(self::hash($tokenValue)) : null;
        if ($token !== null) {
            $this->store->revokeFamily((string) $token['family']);
        }
    }

    /** @return array{client_id:string,user_id:int}|null */
    public function authenticateAccessToken(string $tokenValue): ?array
    {
        if (!str_starts_with($tokenValue, 'sop_at_')) {
            return null;
        }
        $token = $this->store->getToken(self::hash($tokenValue));
        if ($token === null || $token['type'] !== 'access' || $token['revoked'] || $token['expires'] < $this->now()) {
            return null;
        }
        if ($this->store->getClient((string) $token['client_id']) === null) {
            return null;
        }

        return ['client_id' => (string) $token['client_id'], 'user_id' => (int) $token['user_id']];
    }

    // ---- служебное ----------------------------------------------------------------------------------

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /** Строковый параметр запроса; массивы и прочие типы (client_id[]=…) считаются пустыми, без предупреждений PHP. */
    public static function str(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @param array<string,?string> $params */
    private function redirectWith(string $uri, array $params): string
    {
        $query = http_build_query(array_filter($params, static fn ($v) => $v !== null), '', '&', PHP_QUERY_RFC3986);

        return $uri . (str_contains($uri, '?') ? '&' : '?') . $query;
    }
}
