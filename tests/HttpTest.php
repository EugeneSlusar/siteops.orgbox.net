<?php

declare(strict_types=1);

use OrgBox\SiteOps\Auth\BearerTokenAuthenticator;
use OrgBox\SiteOps\Auth\HmacAuthenticator;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Http\ApiEndpoint;
use OrgBox\SiteOps\Http\McpEndpoint;
use OrgBox\SiteOps\Http\RequestGuard;
use OrgBox\SiteOps\Mcp\McpServer;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\Tools\IblockToolset;
use Tests\Support\ArrayOptionStore;
use Tests\Support\FakeGateway;
use Tests\Support\MemoryAudit;
use Tests\Support\MemoryCounter;
use Tests\Support\MemoryNonces;

/** @return array{0:McpEndpoint,1:Settings,2:string} */
function makeEndpoint(array $options = []): array
{
    $settings = new Settings(new ArrayOptionStore($options + ['mcp_enabled' => 'Y']));
    $token = $settings->generateToken();
    $registry = new ToolRegistry();
    (new IblockToolset(new FakeGateway(), $settings, new MemoryAudit()))->registerAll($registry);
    $endpoint = new McpEndpoint(
        $settings,
        new RequestGuard($settings, new MemoryCounter(), 1_000_000),
        new BearerTokenAuthenticator($settings),
        new McpServer($registry, $settings->writesEnabled(), '1.0.0'),
    );

    return [$endpoint, $settings, $token];
}

function server(string $token = '', array $extra = []): array
{
    return $extra + ['REMOTE_ADDR' => '203.0.113.5', 'CONTENT_TYPE' => 'application/json'] + ($token !== '' ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []);
}

$ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

test('HTTP: корректный запрос с токеном проходит', function () use ($ping): void {
    [$endpoint, , $token] = makeEndpoint();
    $r = $endpoint->handle('POST', server($token), $ping);
    assertSame(200, $r->status);
    assertContains('"result"', (string) $r->body);
    assertSame('no-store', $r->headers['Cache-Control']);
});

test('HTTP: выключенный MCP отвечает 404', function () use ($ping): void {
    [$endpoint, , $token] = makeEndpoint(['mcp_enabled' => 'N']);
    assertSame(404, $endpoint->handle('POST', server($token), $ping)->status);
});

test('HTTP: GET не поддерживается (405, Allow: POST)', function (): void {
    [$endpoint, , $token] = makeEndpoint();
    $r = $endpoint->handle('GET', server($token), '');
    assertSame(405, $r->status);
    assertSame('POST', $r->headers['Allow']);
});

test('HTTP: без токена — 401 с WWW-Authenticate', function () use ($ping): void {
    [$endpoint] = makeEndpoint();
    $r = $endpoint->handle('POST', server(), $ping);
    assertSame(401, $r->status);
    assertContains('Bearer', $r->headers['WWW-Authenticate']);
});

test('HTTP: неверный токен — 401', function () use ($ping): void {
    [$endpoint] = makeEndpoint();
    assertSame(401, $endpoint->handle('POST', server('sop_неверный'), $ping)->status);
});

test('HTTP: токен принимается из X-SiteOps-Token, если Authorization не доходит до PHP', function () use ($ping): void {
    [$endpoint, , $token] = makeEndpoint();
    $r = $endpoint->handle('POST', server('', ['HTTP_X_SITEOPS_TOKEN' => $token]), $ping);
    assertSame(200, $r->status);
});

test('HTTP: после отзыва токена доступ закрыт', function () use ($ping): void {
    [$endpoint, $settings, $token] = makeEndpoint();
    $settings->revokeToken();
    assertSame(401, $endpoint->handle('POST', server($token), $ping)->status);
});

test('HTTP: токен в настройках хранится только в виде хэша', function (): void {
    [, $settings, $token] = makeEndpoint();
    assertTrue($settings->tokenHash() !== $token);
    assertSame(hash('sha256', $token), $settings->tokenHash());
    assertTrue(str_starts_with($token, 'sop_') && strlen($token) === 52);
});

test('HTTP: чужой Origin отклоняется (защита от DNS rebinding)', function () use ($ping): void {
    [$endpoint, , $token] = makeEndpoint();
    assertSame(403, $endpoint->handle('POST', server($token, ['HTTP_ORIGIN' => 'https://evil.example']), $ping)->status);
});

test('HTTP: Origin из списка разрешённых принимается', function () use ($ping): void {
    [$endpoint, , $token] = makeEndpoint(['allowed_origins' => 'https://app.example']);
    assertSame(200, $endpoint->handle('POST', server($token, ['HTTP_ORIGIN' => 'https://app.example']), $ping)->status);
});

test('HTTP: список разрешённых IP', function () use ($ping): void {
    [$endpoint, , $token] = makeEndpoint(['allowed_ips' => '198.51.100.7']);
    assertSame(403, $endpoint->handle('POST', server($token), $ping)->status);
    assertSame(200, $endpoint->handle('POST', server($token, ['REMOTE_ADDR' => '198.51.100.7']), $ping)->status);
});

test('HTTP: лимит запросов в минуту (429), в том числе для неверных токенов', function () use ($ping): void {
    [$endpoint] = makeEndpoint(['rate_limit' => '3']);
    for ($i = 0; $i < 3; $i++) {
        assertSame(401, $endpoint->handle('POST', server('sop_x'), $ping)->status);
    }
    assertSame(429, $endpoint->handle('POST', server('sop_x'), $ping)->status);
});

test('HTTP: неверный Content-Type — 415', function () use ($ping): void {
    [$endpoint, , $token] = makeEndpoint();
    assertSame(415, $endpoint->handle('POST', server($token, ['CONTENT_TYPE' => 'text/plain']), $ping)->status);
});

test('HTTP: слишком большое тело — 413', function (): void {
    [$endpoint, , $token] = makeEndpoint();
    assertSame(413, $endpoint->handle('POST', server($token), str_repeat('a', 1048577))->status);
});

test('HTTP: уведомление даёт 202 без тела', function (): void {
    [$endpoint, , $token] = makeEndpoint();
    $r = $endpoint->handle('POST', server($token), '{"jsonrpc":"2.0","method":"notifications/initialized"}');
    assertSame(202, $r->status);
    assertSame(null, $r->body);
});

// ---- старый подписанный API ---------------------------------------------------------------------

function signed(string $secret, string $body, int $ts, string $nonce, string $uri = '/bitrix/tools/orgbox_siteops/api.php'): array
{
    $payload = "POST\n" . $uri . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);

    return [
        'REMOTE_ADDR' => '203.0.113.5',
        'HTTP_X_ORGBOX_KEY' => 'siteops',
        'HTTP_X_ORGBOX_TIMESTAMP' => (string) $ts,
        'HTTP_X_ORGBOX_NONCE' => $nonce,
        'HTTP_X_ORGBOX_SIGNATURE' => hash_hmac('sha256', $payload, $secret),
    ];
}

function makeApi(array $options = []): ApiEndpoint
{
    $settings = new Settings(new ArrayOptionStore($options + ['api_enabled' => 'Y', 'api_secret' => 'top-secret', 'default_iblock_id' => '1']));
    $registry = new ToolRegistry();
    (new IblockToolset(new FakeGateway(), $settings, new MemoryAudit()))->registerAll($registry);

    return new ApiEndpoint(
        $settings,
        new RequestGuard($settings, new MemoryCounter(), 1_000_000),
        new HmacAuthenticator($settings, new MemoryNonces(), 1_000_000),
        $registry,
    );
}

test('API: корректная подпись, операция seo.templates.get', function (): void {
    $body = '{"operation":"seo.templates.get"}';
    $r = makeApi()->handle('POST', signed('top-secret', $body, 1_000_000, 'nonce-nonce-nonce-1'), $body);
    assertSame(200, $r->status);
    assertContains('ELEMENT_META_TITLE', (string) $r->body);
});

test('API: неверная подпись отклоняется', function (): void {
    $body = '{"operation":"seo.templates.get"}';
    assertSame(401, makeApi()->handle('POST', signed('чужой-секрет', $body, 1_000_000, 'nonce-nonce-nonce-1'), $body)->status);
});

test('API: подмена тела после подписи отклоняется', function (): void {
    $server = signed('top-secret', '{"operation":"seo.templates.get"}', 1_000_000, 'nonce-nonce-nonce-1');
    assertSame(401, makeApi()->handle('POST', $server, '{"operation":"tools.list"}')->status);
});

test('API: просроченный запрос отклоняется', function (): void {
    $body = '{"operation":"tools.list"}';
    assertSame(401, makeApi()->handle('POST', signed('top-secret', $body, 1_000_000 - 301, 'nonce-nonce-nonce-1'), $body)->status);
});

test('API: повтор того же nonce отклоняется', function (): void {
    $api = makeApi();
    $body = '{"operation":"tools.list"}';
    $server = signed('top-secret', $body, 1_000_000, 'nonce-nonce-nonce-1');
    assertSame(200, $api->handle('POST', $server, $body)->status);
    assertSame(401, $api->handle('POST', $server, $body)->status);
});

test('API: выключенный API отвечает 403', function (): void {
    $body = '{"operation":"tools.list"}';
    assertSame(403, makeApi(['api_enabled' => 'N'])->handle('POST', signed('top-secret', $body, 1_000_000, 'nonce-nonce-nonce-1'), $body)->status);
});

test('API: запись недоступна в режиме чтения', function (): void {
    $body = '{"operation":"seo.templates.update","templates":{"ELEMENT_META_TITLE":"x"}}';
    $r = makeApi()->handle('POST', signed('top-secret', $body, 1_000_000, 'nonce-nonce-nonce-1'), $body);
    assertSame(400, $r->status);
});

test('API: tools.list в режиме чтения без инструментов записи', function (): void {
    $body = '{"operation":"tools.list"}';
    $r = makeApi()->handle('POST', signed('top-secret', $body, 1_000_000, 'nonce-nonce-nonce-1'), $body);
    $names = array_column(json_decode((string) $r->body, true)['result']['tools'], 'name');
    assertTrue(!in_array('element_update', $names, true));
    assertTrue(in_array('element_list', $names, true));
});
