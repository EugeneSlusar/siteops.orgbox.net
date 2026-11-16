<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/BitrixAdminStubs.php';

use OrgBox\SiteOps\Admin\Csrf;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\FileOptionStore;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\OAuth\OAuthServer;
use Tests\Support\MemoryOAuthStore;

/** Сигнал «запрос завершён»: подменяет exit и редирект в странице согласия. */
final class ExitSignal extends RuntimeException
{
    public function __construct(public readonly ?string $location)
    {
        parent::__construct($location === null ? 'finish' : 'redirect');
    }
}

/**
 * Прогоняет страницу согласия.
 * @return array{html:string,location:?string,ended:bool}
 */
function renderAuthorize(string $base, MemoryOAuthStore $oauthStore, array $query, string $method = 'GET', array $post = [], array $cookies = [], bool $sessid = true): array
{
    $docroot = tempDir();
    mkdir($docroot . '/bitrix/modules/main/include', 0755, true);
    touch($docroot . '/bitrix/modules/main/include/prolog_admin_after.php');
    touch($docroot . '/bitrix/modules/main/include/epilog_admin.php');

    $saved = [$_SERVER, $_POST, $_COOKIE, $_GET];
    $_SERVER['DOCUMENT_ROOT'] = $docroot;
    $_SERVER['HTTP_HOST'] = 'shop.example';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/site.ops/oauth/authorize/?' . http_build_query($query);
    $_GET = $query;
    $_POST = $post;
    $_COOKIE = $cookies;
    $GLOBALS['__sessid_ok'] = $sessid;
    $GLOBALS['APPLICATION'] = new TestApplication();
    $GLOBALS['USER'] = new TestUser();
    $siteops = Bootstrap::standalone($base, null, $oauthStore, function (?string $location): never {
        throw new ExitSignal($location);
    });

    $location = null;
    $ended = false;
    ob_start();
    try {
        require __DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/Admin/authorize.view.php';
    } catch (ExitSignal $signal) {
        $location = $signal->location;
        $ended = $signal->location === null;
    } finally {
        $html = (string) ob_get_clean();
        [$_SERVER, $_POST, $_COOKIE, $_GET] = $saved;
        removeDir($docroot);
    }

    return ['html' => $html, 'location' => $location, 'ended' => $ended];
}

/** Сайт с включёнными MCP и OAuth, зарегистрированный клиент и корректный запрос авторизации. */
function consentFixture(callable $body, string $clientName = 'Claude Code'): void
{
    $dir = tempDir();
    $base = $dir . '/site.ops';
    mkdir($base, 0755, true);
    try {
        $settings = new Settings(new FileOptionStore($base . '/_data/settings.php'));
        $settings->store()->set('mcp_enabled', 'Y');
        $settings->store()->set('oauth_enabled', 'Y');
        $settings->store()->set('allowed_iblocks', '5');
        $store = new MemoryOAuthStore();
        $oauth = new OAuthServer($store, 'https://shop.example');
        $client = $oauth->register(['client_name' => $clientName, 'redirect_uris' => ['http://localhost:7777/callback']]);
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $query = [
            'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'http://localhost:7777/callback',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'state' => 'st-123', 'resource' => 'https://shop.example/site.ops/mcp',
        ];
        $body($base, $store, $query, $verifier, $client['client_id']);
    } finally {
        removeDir($dir);
    }
}

function consentCsrf(string $base): array
{
    $raw = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    $secret = (new Settings(new FileOptionStore($base . '/_data/settings.php')))->csrfSecret();

    return [[Csrf::COOKIE => $raw], [Csrf::FIELD => (new Csrf($secret))->issue($raw)['field'], 'decision' => 'allow']];
}

test('согласие: страница показывает клиента, адрес возврата, режим и инфоблоки; сама ничего не выдаёт', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        $r = renderAuthorize($base, $store, $query);
        assertSame(null, $r['location'], 'GET никогда не выдаёт код и не перенаправляет');
        assertContains('Claude Code', $r['html']);
        assertContains('localhost', $r['html']);
        assertContains('только чтение', $r['html']);
        assertContains('инфоблоки 5', $r['html']);
        assertContains('name="decision" value="allow"', $r['html']);
        assertContains('name="decision" value="deny"', $r['html']);
        assertContains('name="siteops_csrf"', $r['html']);
        assertSame([], $store->codes, 'код не выдан');
    });
});

test('согласие: имя клиента экранируется (клиент сам задаёт имя, оно недоверенное)', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        $html = renderAuthorize($base, $store, $query)['html'];
        assertTrue(!str_contains($html, '<script>alert(1)</script>'), 'сырой script в странице согласия');
        assertContains('&lt;script&gt;', $html);
    }, '<script>alert(1)</script>');
});

test('согласие: «Разрешить» с корректной защитой формы выдаёт одноразовый код и возвращает клиента с state и iss', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query, string $verifier, string $clientId): void {
        renderAuthorize($base, $store, $query); // создаёт секрет
        [$cookies, $post] = consentCsrf($base);
        $r = renderAuthorize($base, $store, $query, 'POST', $post, $cookies, false); // штатный sessid не проходит, собственная защита проходит
        assertTrue($r['location'] !== null && str_starts_with($r['location'], 'http://localhost:7777/callback?'), (string) $r['location']);
        parse_str((string) parse_url($r['location'], PHP_URL_QUERY), $q);
        assertSame('st-123', $q['state']);
        assertSame('https://shop.example/site.ops/oauth', $q['iss']);
        // Код действительно обменивается на токены (полный путь клиента)
        $oauth = new OAuthServer($store, 'https://shop.example');
        $tokens = $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $q['code'], 'code_verifier' => $verifier, 'redirect_uri' => 'http://localhost:7777/callback']);
        assertSame(1, $oauth->authenticateAccessToken($tokens['access_token'])['user_id'], 'токен выдан администратору с id 1');
        $entries = array_filter(CEventLog::$entries, static fn ($e) => ($e['ITEM_ID'] ?? '') === 'oauth.approve');
        assertTrue($entries !== [], 'выдача доступа записана в журнал событий');
    });
});

test('согласие: без защиты формы доступ не выдаётся (подделка запроса «Разрешить» с чужого сайта)', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        renderAuthorize($base, $store, $query);
        [$cookies, $post] = consentCsrf($base);
        $attacks = [
            'нет cookie' => [$post, []],
            'нет поля' => [['decision' => 'allow'], $cookies],
            'чужое поле' => [[Csrf::FIELD => str_repeat('0', 64), 'decision' => 'allow'], $cookies],
        ];
        foreach ($attacks as $name => [$data, $cookie]) {
            $r = renderAuthorize($base, $store, $query, 'POST', $data, $cookie, false);
            assertSame(null, $r['location'], $name . ': произошёл редирект');
            assertContains('Не прошла проверка безопасности формы', $r['html'], $name);
            assertSame([], $store->codes, $name . ': выдан код');
        }
    });
});

test('согласие: запасной вариант — штатный sessid Битрикса тоже подтверждает действие', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        $r = renderAuthorize($base, $store, $query, 'POST', ['decision' => 'allow'], [], true);
        assertTrue($r['location'] !== null && str_contains($r['location'], 'code='));
    });
});

test('согласие: «Отклонить» возвращает access_denied и не выдаёт кода', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        renderAuthorize($base, $store, $query);
        [$cookies, $post] = consentCsrf($base);
        $r = renderAuthorize($base, $store, $query, 'POST', ['decision' => 'deny'] + $post, $cookies, false);
        assertContains('error=access_denied', (string) $r['location']);
        assertContains('state=st-123', (string) $r['location']);
        assertTrue(!str_contains((string) $r['location'], 'code='));
        assertSame([], $store->codes);
    });
});

test('согласие: неизвестное значение решения ничего не выдаёт', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        renderAuthorize($base, $store, $query);
        [$cookies, $post] = consentCsrf($base);
        $r = renderAuthorize($base, $store, $query, 'POST', ['decision' => 'ALLOW; drop'] + $post, $cookies, false);
        assertSame(null, $r['location']);
        assertSame([], $store->codes);
    });
});

test('согласие: неизвестный клиент и чужой redirect_uri — страница с ошибкой, редиректа на непроверенный адрес нет', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        $r = renderAuthorize($base, $store, ['client_id' => 'sopc_unknown'] + $query);
        assertSame(null, $r['location']);
        assertContains('Неизвестный клиент', $r['html']);
        $r = renderAuthorize($base, $store, ['redirect_uri' => 'https://evil.example/cb'] + $query);
        assertSame(null, $r['location'], 'на чужой адрес не перенаправляем');
        assertContains('redirect_uri', $r['html']);
        assertSame([], $store->codes);
    });
});

test('согласие: ошибка запроса при проверенном клиенте возвращается ему редиректом вместе со state', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        unset($query['code_challenge']);
        $r = renderAuthorize($base, $store, $query);
        assertTrue($r['location'] !== null && str_starts_with($r['location'], 'http://localhost:7777/callback?'));
        assertContains('error=invalid_request', $r['location']);
        assertContains('state=st-123', $r['location']);
    });
});

test('согласие: если OAuth выключен, страница недоступна и ничего не выдаёт', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store, array $query): void {
        (new Settings(new FileOptionStore($base . '/_data/settings.php')))->store()->set('oauth_enabled', 'N');
        $r = renderAuthorize($base, $store, $query);
        assertContains('выключено', $r['html']);
        assertSame(null, $r['location']);
        assertSame([], $store->codes);
    });
});
