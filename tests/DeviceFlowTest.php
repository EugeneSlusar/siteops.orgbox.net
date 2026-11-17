<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/BitrixAdminStubs.php';

use OrgBox\SiteOps\Admin\Csrf;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\FileOptionStore;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\OAuth\OAuthException;
use OrgBox\SiteOps\OAuth\OAuthServer;
use Tests\Support\MemoryOAuthStore;

/** @return array{0:OAuthServer,1:MemoryOAuthStore,2:object} */
function deviceFixture(): array
{
    return oauthFixture();
}

function pollError(OAuthServer $oauth, array $form): string
{
    try {
        $oauth->token($form);
    } catch (OAuthException $e) {
        return $e->error;
    }

    return 'ok';
}

function deviceForm(array $start): array
{
    return ['grant_type' => OAuthServer::DEVICE_GRANT, 'device_code' => $start['device_code'], 'client_id' => $start['client_id']];
}

/** Прогон страницы подтверждения (как renderAuthorize, но для verify.view.php). @return array{html:string,ended:bool} */
function renderVerify(string $base, MemoryOAuthStore $oauthStore, array $query, string $method = 'GET', array $post = [], array $cookies = []): array
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
    $_SERVER['REQUEST_URI'] = '/site.ops/oauth/verify/?' . http_build_query($query);
    $_GET = $query;
    $_POST = $post;
    $_COOKIE = $cookies;
    $GLOBALS['__sessid_ok'] = false;
    $GLOBALS['APPLICATION'] = new TestApplication();
    $GLOBALS['USER'] = new TestUser();
    $siteops = Bootstrap::standalone($base, null, $oauthStore, function (?string $location): never {
        throw new ExitSignal($location);
    });

    $ended = false;
    ob_start();
    try {
        require __DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/Admin/verify.view.php';
    } catch (ExitSignal $signal) {
        $ended = true;
    } finally {
        $html = (string) ob_get_clean();
        [$_SERVER, $_POST, $_COOKIE, $_GET] = $saved;
        removeDir($docroot);
    }

    return ['html' => $html, 'ended' => $ended];
}

test('устройство: старт возвращает device_code, user_code вида XXXX-XXXX, ссылку с кодом, срок и интервал', function (): void {
    [$oauth] = deviceFixture();
    $r = $oauth->startDevice(['client_name' => 'CLI'], '203.0.113.5');
    assertTrue(str_starts_with($r['device_code'], 'sop_dc_'));
    assertTrue(preg_match('/^[BCDFGHJKMNPQRSTVWXZ2-9]{4}-[BCDFGHJKMNPQRSTVWXZ2-9]{4}$/', $r['user_code']) === 1, $r['user_code']);
    assertSame(OAUTH_BASE . '/site.ops/oauth/verify/', $r['verification_uri']);
    assertSame($r['verification_uri'] . '?user_code=' . $r['user_code'], $r['verification_uri_complete']);
    assertSame(900, $r['expires_in']);
    assertSame(5, $r['interval']);
});

test('устройство: в хранилище лежат только хэши кодов, не сами коды', function (): void {
    [$oauth, $store] = deviceFixture();
    $r = $oauth->startDevice([], '203.0.113.5');
    $dump = json_encode($store->devices);
    assertTrue(!str_contains($dump, $r['device_code']) && !str_contains($dump, str_replace('-', '', $r['user_code'])), 'код в открытом виде');
});

test('устройство: клиент без client_id создаётся сам с очищенным именем, неизвестный client_id отклоняется', function (): void {
    [$oauth, $store] = deviceFixture();
    $r = $oauth->startDevice(['client_name' => "Мой\nклиент"], '1.1.1.1');
    assertSame('Мой клиент', $store->getClient($r['client_id'])['name']);
    assertSame([], $store->getClient($r['client_id'])['redirect_uris']);
    $again = $oauth->startDevice(['client_id' => $r['client_id']], '1.1.1.1');
    assertSame($r['client_id'], $again['client_id']);
    assertSame('invalid_client', (function () use ($oauth) { try { $oauth->startDevice(['client_id' => 'nope'], '1.1.1.1'); } catch (OAuthException $e) { return $e->error; } return 'ok'; })());
});

test('устройство: до подтверждения — authorization_pending, после — токены, повторно — invalid_grant', function (): void {
    [$oauth, $store, $clock] = deviceFixture();
    $start = $oauth->startDevice([], '1.1.1.1');
    assertSame('authorization_pending', pollError($oauth, deviceForm($start)));
    $clock->now += 5;
    assertTrue($oauth->decideDevice($start['user_code'], true, 7));
    $tokens = $oauth->token(deviceForm($start));
    assertTrue(str_starts_with($tokens['access_token'], 'sop_at_') && str_starts_with($tokens['refresh_token'], 'sop_rt_'));
    assertSame(['client_id' => $start['client_id'], 'user_id' => 7], $oauth->authenticateAccessToken($tokens['access_token']));
    $clock->now += 5;
    assertSame('invalid_grant', pollError($oauth, deviceForm($start)));
});

test('устройство: слишком частый опрос — slow_down, интервал растёт на 5 секунд', function (): void {
    [$oauth, $store, $clock] = deviceFixture();
    $start = $oauth->startDevice([], '1.1.1.1');
    assertSame('authorization_pending', pollError($oauth, deviceForm($start)));
    $clock->now += 2;
    assertSame('slow_down', pollError($oauth, deviceForm($start)));
    assertSame(10, $store->getDevice(OAuthServer::hash($start['device_code']))['interval']);
    $clock->now += 10;
    assertSame('authorization_pending', pollError($oauth, deviceForm($start)));
});

test('устройство: отказ — access_denied; срок истёк — expired_token; решение после истечения невозможно', function (): void {
    [$oauth, $store, $clock] = deviceFixture();
    $a = $oauth->startDevice([], '1.1.1.1');
    assertTrue($oauth->decideDevice($a['user_code'], false, 7));
    assertSame('access_denied', pollError($oauth, deviceForm($a)));
    assertSame('invalid_grant', pollError($oauth, deviceForm($a)), 'запись удалена');

    $b = $oauth->startDevice([], '1.1.1.1');
    $clock->now += 901;
    assertTrue(!$oauth->decideDevice($b['user_code'], true, 7), 'просроченный код подтвердить нельзя');
    assertSame('expired_token', pollError($oauth, deviceForm($b)));
});

test('устройство: решение по коду принимается один раз; ввод кода нечувствителен к регистру и дефису', function (): void {
    [$oauth] = deviceFixture();
    $start = $oauth->startDevice([], '1.1.1.1');
    $typed = strtolower(str_replace('-', ' ', $start['user_code']));
    assertTrue($oauth->pendingDevice($typed) !== null);
    assertTrue($oauth->decideDevice($typed, true, 7));
    assertTrue(!$oauth->decideDevice($start['user_code'], false, 8), 'уже обработанный запрос нельзя перерешить');
    assertTrue($oauth->pendingDevice($start['user_code']) === null);
    assertTrue(!$oauth->decideDevice('AAAA-AAAA', true, 7), 'неверный алфавит');
    assertSame('', OAuthServer::normalizeUserCode('XYZ'));
});

test('устройство: device_code чужого клиента или подделка не работают', function (): void {
    [$oauth] = deviceFixture();
    $a = $oauth->startDevice([], '1.1.1.1');
    $b = $oauth->startDevice([], '1.1.1.1');
    $oauth->decideDevice($a['user_code'], true, 7);
    assertSame('invalid_grant', pollError($oauth, ['grant_type' => OAuthServer::DEVICE_GRANT, 'device_code' => $a['device_code'], 'client_id' => $b['client_id']]));
    assertSame('invalid_grant', pollError($oauth, ['grant_type' => OAuthServer::DEVICE_GRANT, 'device_code' => 'sop_dc_fake', 'client_id' => $a['client_id']]));
    assertSame('invalid_grant', pollError($oauth, ['grant_type' => OAuthServer::DEVICE_GRANT, 'device_code' => ['x'], 'client_id' => $a['client_id']]));
});

test('устройство: число ожидающих запросов ограничено', function (): void {
    [$oauth] = deviceFixture();
    for ($i = 0; $i < 30; $i++) {
        $oauth->startDevice([], '1.1.1.1');
    }
    assertSame('temporarily_unavailable', (function () use ($oauth) { try { $oauth->startDevice([], '1.1.1.1'); } catch (OAuthException $e) { return $e->error; } return 'ok'; })());
});

test('устройство: регистрация клиента только для устройства не требует redirect_uris; без grant остальное по-старому', function (): void {
    [$oauth] = deviceFixture();
    $r = $oauth->register(['client_name' => 'Dev', 'grant_types' => [OAuthServer::DEVICE_GRANT]]);
    assertSame([], $r['redirect_uris']);
    assertSame([OAuthServer::DEVICE_GRANT], $r['grant_types']);
    assertSame('invalid_redirect_uri', (function () use ($oauth) { try { $oauth->register(['client_name' => 'x']); } catch (OAuthException $e) { return $e->error; } return 'ok'; })());
    assertSame('invalid_client_metadata', (function () use ($oauth) { try { $oauth->register(['grant_types' => ['password'], 'redirect_uris' => ['https://a.example/cb']]); } catch (OAuthException $e) { return $e->error; } return 'ok'; })());
});

test('устройство: клиент потока устройства не может пройти поток с кодом авторизации (нет redirect_uri)', function (): void {
    [$oauth] = deviceFixture();
    $start = $oauth->startDevice([], '1.1.1.1');
    try {
        $oauth->validateAuthorizationRequest(['client_id' => $start['client_id'], 'redirect_uri' => 'https://evil.example/cb', 'response_type' => 'code']);
        throw new AssertionError('redirect принят');
    } catch (\OrgBox\SiteOps\OAuth\AuthorizeException) {
    }
});

test('устройство: документ сервера объявляет device_authorization_endpoint и grant', function (): void {
    [$oauth] = deviceFixture();
    $meta = $oauth->authorizationServerMetadata();
    assertSame(OAUTH_BASE . '/site.ops/oauth/device/', $meta['device_authorization_endpoint']);
    assertContains(OAuthServer::DEVICE_GRANT, implode(',', $meta['grant_types_supported']));
});

test('устройство, HTTP: /device выдаёт коды, /token опрашивает, ошибки в формате RFC 8628', function (): void {
    [$endpoint, , , $settings] = oauthHttp();
    $server = ['REMOTE_ADDR' => '203.0.113.5', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
    $r = $endpoint->handle('device', 'POST', $server, 'client_name=CLI');
    assertSame(200, $r->status);
    $start = json_decode((string) $r->body, true);
    assertTrue(isset($start['device_code'], $start['user_code'], $start['verification_uri_complete']));
    $poll = $endpoint->handle('token', 'POST', $server, http_build_query(['grant_type' => OAuthServer::DEVICE_GRANT, 'device_code' => $start['device_code'], 'client_id' => $start['client_id']]));
    assertSame(400, $poll->status);
    assertSame('authorization_pending', json_decode((string) $poll->body, true)['error']);
    assertSame(405, $endpoint->handle('device', 'GET', $server, '')->status);
});

test('страница подтверждения: без кода — форма ввода; с кодом — клиент, код, режим, IP; GET ничего не выдаёт', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store): void {
        $oauth = new OAuthServer($store, 'https://shop.example');
        $empty = renderVerify($base, $store, []);
        assertContains('name="user_code"', $empty['html']);
        $start = $oauth->startDevice(['client_name' => 'CLI <b>'], '203.0.113.5');
        $r = renderVerify($base, $store, ['user_code' => $start['user_code']]);
        assertContains($start['user_code'], $r['html']);
        assertContains('203.0.113.5', $r['html']);
        assertContains('только чтение', $r['html']);
        assertContains('CLI &lt;b&gt;', $r['html']);
        assertContains('name="decision" value="allow"', $r['html']);
        assertSame('pending', $store->getDeviceByUserCode(OAuthServer::hash(OAuthServer::normalizeUserCode($start['user_code'])))['status']);
        assertContains('не найден', renderVerify($base, $store, ['user_code' => 'BBBB-BBBB'])['html']);
    });
});

test('страница подтверждения: «Разрешить» с защитой формы подтверждает, без защиты — нет', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store): void {
        $oauth = new OAuthServer($store, 'https://shop.example');
        $start = $oauth->startDevice(['client_name' => 'CLI'], '1.1.1.1');
        $hash = OAuthServer::hash(OAuthServer::normalizeUserCode($start['user_code']));
        renderVerify($base, $store, ['user_code' => $start['user_code']]); // создаёт секрет
        [$cookies, $post] = consentCsrf($base);
        $post['user_code'] = $start['user_code'];

        $bad = renderVerify($base, $store, [], 'POST', ['user_code' => $start['user_code'], 'decision' => 'allow']);
        assertContains('Не прошла проверка', $bad['html']);
        assertSame('pending', $store->getDeviceByUserCode($hash)['status']);

        $ok = renderVerify($base, $store, [], 'POST', $post, $cookies);
        assertContains('Доступ выдан', $ok['html']);
        assertSame('approved', $store->getDeviceByUserCode($hash)['status']);
        assertSame(1, $store->getDeviceByUserCode($hash)['user_id']);
    });
});

test('страница подтверждения: «Отклонить» закрывает запрос', function (): void {
    consentFixture(function (string $base, MemoryOAuthStore $store): void {
        $oauth = new OAuthServer($store, 'https://shop.example');
        $start = $oauth->startDevice([], '1.1.1.1');
        $hash = OAuthServer::hash(OAuthServer::normalizeUserCode($start['user_code']));
        renderVerify($base, $store, []);
        [$cookies, $post] = consentCsrf($base);
        $post['user_code'] = $start['user_code'];
        $post['decision'] = 'deny';
        assertContains('отклонено', renderVerify($base, $store, [], 'POST', $post, $cookies)['html']);
        assertSame('denied', $store->getDeviceByUserCode($hash)['status']);
    });
});
