<?php

declare(strict_types=1);

use OrgBox\SiteOps\Auth\AuthException;
use OrgBox\SiteOps\Auth\BearerTokenAuthenticator;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Http\McpEndpoint;
use OrgBox\SiteOps\Http\OAuthEndpoint;
use OrgBox\SiteOps\Http\RequestGuard;
use OrgBox\SiteOps\Mcp\McpServer;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\OAuth\AuthorizeException;
use OrgBox\SiteOps\OAuth\OAuthException;
use OrgBox\SiteOps\OAuth\OAuthServer;
use Tests\Support\ArrayOptionStore;
use Tests\Support\MemoryCounter;
use Tests\Support\MemoryOAuthStore;

const OAUTH_BASE = 'https://shop.example';

/** @return array{0:OAuthServer,1:MemoryOAuthStore,2:object} сервер, хранилище, управляемые часы */
function oauthFixture(): array
{
    $clock = new class {
        public int $now = 1_000_000;
    };
    $store = new MemoryOAuthStore();

    return [new OAuthServer($store, OAUTH_BASE, fn (): int => $clock->now), $store, $clock];
}

function pkce(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

/** Регистрирует клиента и проходит страницу согласия: возвращает [client_id, code, verifier, redirect_uri]. */
function authorizeClient(OAuthServer $oauth, string $redirect = 'https://claude.ai/api/mcp/auth_callback'): array
{
    $client = $oauth->register(['client_name' => 'Claude', 'redirect_uris' => [$redirect]]);
    [$verifier, $challenge] = pkce();
    $request = $oauth->validateAuthorizationRequest([
        'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => $redirect,
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz', 'resource' => OAUTH_BASE . '/site.ops/mcp',
    ]);
    $url = $oauth->approve($request, 7);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return [$client['client_id'], $q['code'], $verifier, $redirect, $q, $url];
}

// ---- документы обнаружения -------------------------------------------------------------------

test('OAuth: документ сервера авторизации — только код + PKCE S256, публичные клиенты', function (): void {
    [$oauth] = oauthFixture();
    $m = $oauth->authorizationServerMetadata();
    assertSame(OAUTH_BASE . '/site.ops/oauth', $m['issuer']);
    assertSame(['S256'], $m['code_challenge_methods_supported']);
    assertSame(['code'], $m['response_types_supported']);
    assertSame(['none'], $m['token_endpoint_auth_methods_supported']);
    assertSame(OAUTH_BASE . '/site.ops/oauth/register/', $m['registration_endpoint']);
    assertTrue(str_ends_with($m['token_endpoint'], '/'), 'адреса со слэшем: без редиректа Apache при POST');
});

test('OAuth: документ защищаемого ресурса указывает на MCP-адрес и сервер авторизации', function (): void {
    [$oauth] = oauthFixture();
    $m = $oauth->protectedResourceMetadata();
    assertSame(OAUTH_BASE . '/site.ops/mcp', $m['resource']);
    assertSame([OAUTH_BASE . '/site.ops/oauth'], $m['authorization_servers']);
    assertSame(OAUTH_BASE . '/site.ops/oauth/.well-known/oauth-protected-resource/', $oauth->resourceMetadataUrl());
});

// ---- регистрация клиента ---------------------------------------------------------------------

test('регистрация: клиент создаётся, имя очищается и обрезается', function (): void {
    [$oauth, $store] = oauthFixture();
    $c = $oauth->register(['client_name' => "  Claude\n\x00Code " . str_repeat('я', 200), 'redirect_uris' => ['http://localhost:33418/callback']]);
    assertTrue(str_starts_with($c['client_id'], 'sopc_'));
    assertSame('none', $c['token_endpoint_auth_method']);
    assertTrue(mb_strlen($c['client_name']) <= 100);
    assertTrue(!str_contains($c['client_name'], "\n") && !str_contains($c['client_name'], "\0"));
    assertSame(1, $store->countClients());
});

test('регистрация: допустимые и недопустимые redirect_uri', function (): void {
    foreach (['https://claude.ai/cb', 'http://localhost:8080/cb', 'http://127.0.0.1:9/cb', 'http://[::1]:9/cb'] as $ok) {
        assertTrue(OAuthServer::isAllowedRedirectUri($ok), $ok);
    }
    foreach (['http://evil.example/cb', 'javascript:alert(1)', 'https://x.example/cb#frag', 'https://user:pw@x.example/cb', 'ftp://x.example',
        'https://x.example/a b', '//x.example/cb', '/relative', 'myapp://cb', "https://x.example/cb\r\nLocation: x"] as $bad) {
        assertTrue(!OAuthServer::isAllowedRedirectUri($bad), $bad);
    }
});

test('регистрация: плохие данные отклоняются', function (): void {
    [$oauth] = oauthFixture();
    foreach ([[], ['redirect_uris' => []], ['redirect_uris' => 'https://a.example/cb'], ['redirect_uris' => ['http://evil.example/cb']],
        ['redirect_uris' => array_fill(0, 6, 'https://a.example/cb')]] as $payload) {
        assertThrows(OAuthException::class, fn () => $oauth->register($payload), '');
    }
    assertThrows(OAuthException::class, fn () => $oauth->register(['redirect_uris' => ['https://a.example/cb'], 'token_endpoint_auth_method' => 'client_secret_basic']), 'none');
    assertThrows(OAuthException::class, fn () => $oauth->register(['redirect_uris' => ['https://a.example/cb'], 'grant_types' => ['password']]), 'grant_types');
});

test('регистрация: предел числа клиентов и очистка неиспользуемых старых', function (): void {
    [$oauth, $store, $clock] = oauthFixture();
    for ($i = 0; $i < 200; $i++) {
        $store->saveClient(['client_id' => 'c' . $i, 'name' => 'x', 'redirect_uris' => ['https://a.example/cb'], 'created' => $clock->now]);
    }
    assertThrows(OAuthException::class, fn () => $oauth->register(['redirect_uris' => ['https://a.example/cb']]), 'Слишком много');
    $clock->now += 8 * 86400; // через 8 суток неиспользуемые клиенты удаляются при регистрации
    $oauth->register(['redirect_uris' => ['https://a.example/cb']]);
    assertSame(1, $store->countClients());
});

// ---- страница согласия -----------------------------------------------------------------------

test('авторизация: корректный запрос принимается, адрес возврата получает code, state и iss', function (): void {
    [$oauth] = oauthFixture();
    [, $code, , $redirect, $q, $url] = authorizeClient($oauth);
    assertTrue(str_starts_with($url, $redirect . '?'));
    assertSame('xyz', $q['state']);
    assertSame(OAUTH_BASE . '/site.ops/oauth', $q['iss']);
    assertTrue(str_starts_with($code, 'sop_code_'));
});

test('авторизация: неизвестный клиент и чужой redirect_uri показываются на странице, а не редиректом', function (): void {
    [$oauth] = oauthFixture();
    $client = $oauth->register(['redirect_uris' => ['https://claude.ai/cb']]);
    [, $challenge] = pkce();
    $base = ['response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
    try {
        $oauth->validateAuthorizationRequest($base + ['client_id' => 'sopc_unknown', 'redirect_uri' => 'https://evil.example/cb']);
        throw new AssertionError('ожидалось исключение');
    } catch (AuthorizeException $e) {
        assertSame(null, $e->redirectUri, 'нельзя перенаправлять на непроверенный адрес');
        assertSame(null, $oauth->errorRedirect($e));
    }
    try {
        $oauth->validateAuthorizationRequest($base + ['client_id' => $client['client_id'], 'redirect_uri' => 'https://claude.ai/cb/../evil']);
        throw new AssertionError('ожидалось исключение');
    } catch (AuthorizeException $e) {
        assertSame(null, $e->redirectUri);
    }
});

test('авторизация: redirect_uri сравнивается точно (префикс и регистр не проходят)', function (): void {
    [$oauth] = oauthFixture();
    $client = $oauth->register(['redirect_uris' => ['https://claude.ai/cb']]);
    [, $challenge] = pkce();
    foreach (['https://claude.ai/cb/', 'https://claude.ai/cb?x=1', 'https://CLAUDE.ai/cb', 'https://claude.ai/cbx', 'https://claude.ai'] as $uri) {
        assertThrows(AuthorizeException::class, fn () => $oauth->validateAuthorizationRequest([
            'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => $uri, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]), '');
    }
});

test('авторизация: PKCE обязателен и только S256; response_type только code', function (): void {
    [$oauth] = oauthFixture();
    $client = $oauth->register(['redirect_uris' => ['https://claude.ai/cb']]);
    [, $challenge] = pkce();
    $ok = ['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'https://claude.ai/cb', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 's1'];
    foreach ([['code_challenge' => null], ['code_challenge_method' => 'plain'], ['code_challenge_method' => null], ['code_challenge' => 'short'], ['response_type' => 'token']] as $override) {
        try {
            $oauth->validateAuthorizationRequest(array_filter($override + $ok, static fn ($v) => $v !== null));
            throw new AssertionError('ожидалось исключение для ' . json_encode($override));
        } catch (AuthorizeException $e) {
            // клиент и адрес проверены: ошибка возвращается редиректом вместе со state
            assertSame('https://claude.ai/cb', $e->redirectUri);
            assertContains('state=s1', (string) $oauth->errorRedirect($e));
        }
    }
});

test('авторизация: resource должен совпадать с MCP-адресом этого сайта', function (): void {
    [$oauth] = oauthFixture();
    $client = $oauth->register(['redirect_uris' => ['https://claude.ai/cb']]);
    [, $challenge] = pkce();
    $q = ['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'https://claude.ai/cb', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
    assertSame(OAUTH_BASE . '/site.ops/mcp', $oauth->validateAuthorizationRequest($q + ['resource' => OAUTH_BASE . '/site.ops/mcp/'])['resource']);
    assertThrows(AuthorizeException::class, fn () => $oauth->validateAuthorizationRequest($q + ['resource' => 'https://other.example/mcp']), 'resource');
});

test('авторизация: отказ пользователя возвращает access_denied со state', function (): void {
    [$oauth] = oauthFixture();
    $client = $oauth->register(['redirect_uris' => ['https://claude.ai/cb']]);
    [, $challenge] = pkce();
    $req = $oauth->validateAuthorizationRequest(['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'https://claude.ai/cb', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'abc']);
    $url = $oauth->deny($req);
    assertContains('error=access_denied', $url);
    assertContains('state=abc', $url);
    assertTrue(!str_contains($url, 'code='));
});

// ---- обмен кода на токены ---------------------------------------------------------------------

test('токен: полный сценарий — код → токены → доступ → обновление → отзыв', function (): void {
    [$oauth, $store] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);

    $t = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    assertSame('Bearer', $t['token_type']);
    assertSame(3600, $t['expires_in']);
    assertTrue(str_starts_with($t['access_token'], 'sop_at_') && str_starts_with($t['refresh_token'], 'sop_rt_'));
    assertSame(['client_id' => $clientId, 'user_id' => 7], $oauth->authenticateAccessToken($t['access_token']));

    $t2 = $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $t['refresh_token']]);
    assertTrue($t2['access_token'] !== $t['access_token'] && $t2['refresh_token'] !== $t['refresh_token'], 'ротация токенов');
    assertTrue($oauth->authenticateAccessToken($t2['access_token']) !== null);

    $oauth->revoke($t2['access_token']);
    assertSame(null, $oauth->authenticateAccessToken($t2['access_token']));
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $t2['refresh_token']]), 'уже использован');
});

test('токен: в хранилище лежат только хэши, ни код, ни токены в открытом виде', function (): void {
    [$oauth, $store] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $t = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    $dump = json_encode([$store->clients, $store->codes, $store->tokens]);
    foreach ([$code, $t['access_token'], $t['refresh_token'], $verifier] as $secret) {
        assertTrue(!str_contains($dump, $secret), 'секрет в открытом виде');
    }
});

test('токен: код одноразовый — повторный обмен отклоняется', function (): void {
    [$oauth] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $form = ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect];
    $oauth->token($form);
    assertThrows(OAuthException::class, fn () => $oauth->token($form), 'использован');
});

test('токен: неверный PKCE-verifier отклоняется и код сгорает', function (): void {
    [$oauth] = oauthFixture();
    [$clientId, $code, , $redirect] = authorizeClient($oauth);
    [$wrong] = pkce();
    $form = ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $wrong, 'redirect_uri' => $redirect];
    assertThrows(OAuthException::class, fn () => $oauth->token($form), 'PKCE');
    assertThrows(OAuthException::class, fn () => $oauth->token($form), 'использован'); // перебор verifier невозможен
});

test('токен: перехваченный код бесполезен без verifier, чужому клиенту и с другим redirect_uri', function (): void {
    [$oauth] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $other = $oauth->register(['redirect_uris' => ['https://evil.example/cb']]);
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $other['client_id'], 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => 'https://evil.example/cb']), 'недействителен');
    [$c2, $code2, $v2] = authorizeClient($oauth);
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $c2, 'code' => $code2, 'code_verifier' => $v2, 'redirect_uri' => 'https://claude.ai/other']), 'redirect_uri');
    [$c3, $code3] = authorizeClient($oauth);
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $c3, 'code' => $code3, 'redirect_uri' => $redirect]), 'code_verifier');
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $c3, 'code' => 'sop_code_подделка', 'code_verifier' => $verifier, 'redirect_uri' => $redirect]), '');
});

test('токен: просроченный код отклоняется', function (): void {
    [$oauth, , $clock] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $clock->now += OAuthServer::CODE_TTL + 1;
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]), 'просрочен');
});

test('токен: неизвестный клиент, неподдерживаемый grant', function (): void {
    [$oauth] = oauthFixture();
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'authorization_code', 'client_id' => 'sopc_x']), 'Неизвестный клиент');
    $c = $oauth->register(['redirect_uris' => ['https://a.example/cb']]);
    foreach (['password', 'client_credentials', ''] as $grant) {
        assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => $grant, 'client_id' => $c['client_id']]), 'Поддерживаются');
    }
});

test('токен: access-токен истекает через час', function (): void {
    [$oauth, , $clock] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $t = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    $clock->now += 3599;
    assertTrue($oauth->authenticateAccessToken($t['access_token']) !== null);
    $clock->now += 2;
    assertSame(null, $oauth->authenticateAccessToken($t['access_token']));
});

test('токен: refresh истекает через 30 суток', function (): void {
    [$oauth, , $clock] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $t = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    $clock->now += OAuthServer::REFRESH_TTL + 1;
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $t['refresh_token']]), 'истёк');
});

test('токен: повторное предъявление использованного refresh отзывает всю цепочку (защита от кражи)', function (): void {
    [$oauth] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $t1 = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    $t2 = $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $t1['refresh_token']]);
    // злоумышленник предъявляет старый refresh
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $t1['refresh_token']]), 'отозван');
    assertSame(null, $oauth->authenticateAccessToken($t2['access_token']), 'доступ законного клиента тоже отозван — нужно подключиться заново');
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $t2['refresh_token']]), 'уже использован');
});

test('токен: refresh нельзя использовать от имени другого клиента, access вместо refresh не принимается', function (): void {
    [$oauth] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $t = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    $other = $oauth->register(['redirect_uris' => ['https://evil.example/cb']]);
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $other['client_id'], 'refresh_token' => $t['refresh_token']]), 'недействителен');
    assertThrows(OAuthException::class, fn () => $oauth->token(['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $t['access_token']]), 'недействителен');
    assertSame(null, $oauth->authenticateAccessToken($t['refresh_token']), 'refresh не годится как access');
});

test('отзыв клиента из админки: токены перестают работать', function (): void {
    [$oauth, $store] = oauthFixture();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $t = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    assertSame(1, count($store->listConnections(1_000_000)));
    $store->revokeClient($clientId);
    assertSame(null, $oauth->authenticateAccessToken($t['access_token']));
    assertSame([], $store->listConnections(1_000_000));
});

// ---- HTTP-слой и связка с MCP ---------------------------------------------------------------

/** @return array{0:OAuthEndpoint,1:McpEndpoint,2:OAuthServer,3:Settings} */
function oauthHttp(array $options = []): array
{
    $settings = new Settings(new ArrayOptionStore($options + ['mcp_enabled' => 'Y', 'oauth_enabled' => 'Y']));
    [$oauth] = oauthFixture();
    $guard = new RequestGuard($settings, new MemoryCounter(), 1_000_000);
    $registry = new ToolRegistry();
    $mcp = new McpEndpoint($settings, $guard, new BearerTokenAuthenticator($settings, $oauth), new McpServer($registry, false, '1.0.0'), $oauth->resourceMetadataUrl());

    return [new OAuthEndpoint($settings, $guard, $oauth), $mcp, $oauth, $settings];
}

$srv = ['REMOTE_ADDR' => '203.0.113.9', 'CONTENT_TYPE' => 'application/json'];

test('HTTP: OAuth выключен — все адреса отвечают 404', function () use ($srv): void {
    [$http] = oauthHttp(['oauth_enabled' => 'N']);
    foreach (OAuthEndpoint::ROUTES as $route) {
        assertSame(404, $http->handle($route, $route === 'as-metadata' || $route === 'resource-metadata' ? 'GET' : 'POST', $srv, '{}')->status, $route);
    }
});

test('HTTP: документы обнаружения отдаются по GET с CORS, другие методы запрещены', function () use ($srv): void {
    [$http] = oauthHttp();
    $r = $http->handle('as-metadata', 'GET', $srv, '');
    assertSame(200, $r->status);
    assertSame('*', $r->headers['Access-Control-Allow-Origin']);
    assertSame(OAUTH_BASE . '/site.ops/oauth', json_decode((string) $r->body, true)['issuer']);
    assertSame(405, $http->handle('as-metadata', 'POST', $srv, '')->status);
    assertSame(405, $http->handle('token', 'GET', $srv, '')->status);
    assertSame(204, $http->handle('token', 'OPTIONS', $srv, '')->status);
});

test('HTTP: полный сценарий клиента MCP — 401 с resource_metadata → регистрация → токен → вызов MCP', function () use ($srv): void {
    [$http, $mcp, $oauth] = oauthHttp();
    $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

    // 1. Клиент обращается к MCP без токена и узнаёт, где искать описание защиты.
    $r = $mcp->handle('POST', $srv, $ping);
    assertSame(401, $r->status);
    assertContains('resource_metadata="' . OAUTH_BASE . '/site.ops/oauth/.well-known/oauth-protected-resource/"', $r->headers['WWW-Authenticate']);

    // 2. Регистрируется.
    $reg = $http->handle('register', 'POST', $srv, json_encode(['client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:7777/callback']]));
    assertSame(201, $reg->status);
    $client = json_decode((string) $reg->body, true);

    // 3. Администратор подтверждает в браузере (страница согласия).
    [$verifier, $challenge] = pkce();
    $request = $oauth->validateAuthorizationRequest(['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'http://localhost:7777/callback', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'st']);
    parse_str((string) parse_url($oauth->approve($request, 1), PHP_URL_QUERY), $back);

    // 4. Меняет код на токены.
    $form = $srv + ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
    $tok = $http->handle('token', 'POST', $form, http_build_query(['grant_type' => 'authorization_code', 'client_id' => $client['client_id'], 'code' => $back['code'], 'code_verifier' => $verifier, 'redirect_uri' => 'http://localhost:7777/callback']));
    assertSame(200, $tok->status);
    assertSame('no-store', $tok->headers['Cache-Control']);
    $tokens = json_decode((string) $tok->body, true);

    // 5. Вызывает MCP с токеном доступа.
    $ok = $mcp->handle('POST', $srv + ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token']], $ping);
    assertSame(200, $ok->status);

    // 6. Отзывает; доступ закрыт.
    $http->handle('revoke', 'POST', $form, http_build_query(['token' => $tokens['access_token']]));
    assertSame(401, $mcp->handle('POST', $srv + ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token']], $ping)->status);
});

test('HTTP: ошибки токена в формате OAuth (error, error_description)', function () use ($srv): void {
    [$http] = oauthHttp();
    $r = $http->handle('token', 'POST', $srv, http_build_query(['grant_type' => 'authorization_code', 'client_id' => 'sopc_nope']));
    assertSame(401, $r->status);
    $b = json_decode((string) $r->body, true);
    assertSame('invalid_client', $b['error']);
    assertTrue(isset($b['error_description']));
});

test('HTTP: регистрация требует JSON и не принимает мусор', function () use ($srv): void {
    [$http] = oauthHttp();
    assertSame(400, $http->handle('register', 'POST', ['CONTENT_TYPE' => 'text/plain'] + $srv, '{}')->status);
    assertSame(400, $http->handle('register', 'POST', $srv, 'не json')->status);
    assertSame(400, $http->handle('register', 'POST', $srv, '{"redirect_uris":["http://evil.example/cb"]}')->status);
});

test('HTTP: OAuth-токен не принимается, если OAuth выключен, а статический работает как раньше', function () use ($srv): void {
    [, $mcp, $oauth, $settings] = oauthHttp();
    [$clientId, $code, $verifier, $redirect] = authorizeClient($oauth);
    $t = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
    $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
    assertSame(200, $mcp->handle('POST', $srv + ['HTTP_AUTHORIZATION' => 'Bearer ' . $t['access_token']], $ping)->status);

    $settings->store()->set('oauth_enabled', 'N');
    assertSame(401, $mcp->handle('POST', $srv + ['HTTP_AUTHORIZATION' => 'Bearer ' . $t['access_token']], $ping)->status);

    $static = $settings->generateToken();
    assertSame(200, $mcp->handle('POST', $srv + ['HTTP_AUTHORIZATION' => 'Bearer ' . $static], $ping)->status);
});

test('HTTP: лимит запросов действует и на адреса OAuth', function () use ($srv): void {
    [$http] = oauthHttp(['rate_limit' => '2']);
    assertSame(200, $http->handle('as-metadata', 'GET', $srv, '')->status);
    assertSame(200, $http->handle('as-metadata', 'GET', $srv, '')->status);
    assertSame(429, $http->handle('as-metadata', 'GET', $srv, '')->status);
});

test('HTTP: список разрешённых IP не блокирует адреса OAuth (на них ходит сервер клиента, а не пользователь)', function () use ($srv): void {
    [$http] = oauthHttp(['allowed_ips' => '198.51.100.7']);
    assertSame(200, $http->handle('as-metadata', 'GET', $srv, '')->status);
});

test('разбор параметров: массивы и мусор вместо строк отклоняются без предупреждений PHP', function (): void {
    [$oauth] = oauthFixture();
    // Предупреждение «Array to string conversion» в тестах превращается в исключение ErrorException.
    foreach ([['client_id' => ['a']], ['client_id' => 'x', 'redirect_uri' => ['https://a.example/cb']], ['client_id' => new stdClass()]] as $query) {
        assertThrows(AuthorizeException::class, fn () => $oauth->validateAuthorizationRequest($query), '');
    }
    $client = $oauth->register(['redirect_uris' => ['https://claude.ai/cb']]);
    [, $challenge] = pkce();
    foreach (['code_challenge' => [$challenge], 'code_challenge_method' => ['S256'], 'response_type' => ['code'], 'state' => ['x'], 'resource' => ['y']] as $key => $value) {
        $q = ['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'https://claude.ai/cb', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
        $q[$key] = $value;
        try {
            $oauth->validateAuthorizationRequest($q);
            assertTrue($key === 'state', 'ожидалось исключение для ' . $key); // state-массив просто игнорируется
        } catch (AuthorizeException) {
        }
    }
    foreach ([['grant_type' => ['authorization_code']], ['grant_type' => 'authorization_code', 'client_id' => ['x']], ['grant_type' => 'refresh_token', 'client_id' => $client['client_id'], 'refresh_token' => ['x']],
        ['grant_type' => 'authorization_code', 'client_id' => $client['client_id'], 'code' => ['x'], 'code_verifier' => ['y']]] as $form) {
        assertThrows(OAuthException::class, fn () => $oauth->token($form), '');
    }
    $oauth->revoke(OAuthServer::str(['token' => ['x']], 'token')); // не падает
});

test('HTTP: массивы в теле запроса токена и отзыва не вызывают предупреждений', function () use ($srv): void {
    [$http] = oauthHttp();
    assertSame(401, $http->handle('token', 'POST', $srv, 'grant_type[]=a&client_id[]=b')->status);
    assertSame(200, $http->handle('revoke', 'POST', $srv, 'token[]=x')->status);
});
