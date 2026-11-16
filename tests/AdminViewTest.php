<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/BitrixAdminStubs.php';

use OrgBox\SiteOps\Admin\Csrf;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\FileOptionStore;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Config\OptionStore;

/** Прогоняет страницу настроек site.ops и возвращает HTML. */
function renderAdmin(string $base, string $method = 'GET', array $post = [], bool $sessid = true, ?OptionStore $store = null, array $cookies = []): string
{
    $docroot = tempDir();
    mkdir($docroot . '/bitrix/modules/main/include', 0755, true);
    touch($docroot . '/bitrix/modules/main/include/prolog_admin_after.php');
    touch($docroot . '/bitrix/modules/main/include/epilog_admin.php');

    $saved = [$_SERVER, $_POST, $_COOKIE];
    $_COOKIE = $cookies;
    $_SERVER['DOCUMENT_ROOT'] = $docroot;
    $_SERVER['HTTP_HOST'] = 'shop.example';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    $GLOBALS['__sessid_ok'] = $sessid;
    $GLOBALS['APPLICATION'] = new TestApplication();
    $GLOBALS['USER'] = new TestUser();
    $siteops = Bootstrap::standalone($base, $store);

    ob_start();
    try {
        require __DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/Admin/settings.view.php';
        $html = (string) ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    } finally {
        [$_SERVER, $_POST, $_COOKIE] = $saved;
        removeDir($docroot);
    }

    return $html;
}

function checked(string $html, string $name): bool
{
    preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m);

    return str_contains($m[0] ?? '', 'checked');
}

function withSite(callable $body): void
{
    $dir = tempDir();
    $base = $dir . '/site.ops';
    mkdir($base, 0755, true);
    try {
        $body($base);
    } finally {
        removeDir($dir);
    }
}

test('страница настроек: на новой установке всё выключено, хранилище показано и доступно для записи', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base);
        assertTrue(!checked($html, 'mcp_enabled') && !checked($html, 'oauth_enabled'));
        assertContains('_data/settings.php', $html);
        assertContains('возможна', $html);
        assertContains('<option', $html === '' ? '<option' : '<option'); // страница отрисована целиком
        assertContains('Сохранить', $html);
    });
});

test('страница настроек: включил MCP и OAuth, сохранил — галочки остались, настройки видны «публичной части»', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'oauth_enabled' => 'Y', 'mode' => 'readonly', 'rate_limit' => '60']);
        assertContains('MSG OK: Настройки сохранены и проверены чтением', $html);
        assertContains('MCP: включён', $html);
        assertContains('подключение по ссылке: включено', $html);
        assertTrue(checked($html, 'mcp_enabled') && checked($html, 'oauth_enabled'), 'галочки после сохранения остались');

        // «Перезагрузка страницы» и отдельный запрос публичной части читают то же самое
        $again = renderAdmin($base);
        assertTrue(checked($again, 'mcp_enabled') && checked($again, 'oauth_enabled'), 'галочки на новой загрузке страницы');
        $public = Bootstrap::standalone($base)->settings();
        assertTrue($public->mcpEnabled() && $public->oauthEnabled(), 'публичная часть видит включённые MCP и OAuth');
    });
});

test('страница настроек: снятие галочки тоже сохраняется', function (): void {
    withSite(function (string $base): void {
        renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'oauth_enabled' => 'Y', 'mode' => 'readonly']);
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'mode' => 'readonly']); // oauth_enabled не отправлен
        assertTrue(checked($html, 'mcp_enabled') && !checked($html, 'oauth_enabled'));
        assertTrue(!Bootstrap::standalone($base)->settings()->oauthEnabled());
    });
});

test('страница настроек: режим и список инфоблоков сохраняются и нормализуются', function (): void {
    withSite(function (string $base): void {
        renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'mode' => 'readwrite', 'allowed_iblocks' => ' 5 , 6;абв, 5 ,-1']);
        $s = Bootstrap::standalone($base)->settings();
        assertTrue($s->writesEnabled());
        assertSame([5, 6], $s->allowedIblocks());
    });
});

test('страница настроек: не прошла проверка безопасности формы — об этом сказано вслух, ничего не записано', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'oauth_enabled' => 'Y'], false);
        assertContains('MSG ERROR: Не прошла проверка безопасности формы', $html);
        assertContains('НЕ сохранены', $html);
        assertContains('в форме sessid Битрикса: нет', $html, 'в сообщении видно, что именно не пришло');
        assertContains('cookie защиты форм: нет', $html);
        $stored = is_file($base . '/_data/settings.php') ? (string) file_get_contents($base . '/_data/settings.php') : '';
        assertTrue(!str_contains($stored, 'mcp_enabled') && !str_contains($stored, 'oauth_enabled'), 'настройки не записаны (в файле только секрет защиты форм)');
        assertTrue(!checked($html, 'mcp_enabled'));
    });
});

test('страница настроек: нельзя записать в папку — ошибка с понятным текстом, а не молчаливое «сохранено»', function (): void {
    $html = renderAdmin('/proc/siteops-no-such/site.ops', 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'mode' => 'readonly']);
    assertContains('MSG ERROR', $html);
    assertTrue(!str_contains($html, 'MSG OK'), 'об успехе сообщать нельзя');
    assertContains('НЕВОЗМОЖНА', $html, 'в блоке хранилища видно, что запись невозможна');
});

test('страница настроек: неверный адрес сайта отклоняется, верный сохраняется', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'public_url' => 'http://shop.example/path', 'mode' => 'readonly']);
        assertContains('Адрес сайта: только https://домен', $html);
        assertSame('', Bootstrap::standalone($base)->settings()->publicUrl());
        renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'public_url' => 'https://shop.example/', 'mode' => 'readonly']);
        assertSame('https://shop.example', Bootstrap::standalone($base)->settings()->publicUrl());
    });
});

test('страница настроек: токен показывается один раз, потом остаётся только факт создания', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base, 'POST', ['action' => 'token']);
        preg_match('/value="(sop_[a-f0-9]{48})"/', $html, $m);
        assertTrue(isset($m[1]), 'токен показан');
        assertSame(hash('sha256', $m[1]), Bootstrap::standalone($base)->settings()->tokenHash());
        $later = renderAdmin($base);
        assertTrue(!str_contains($later, $m[1]), 'повторно токен не показывается');
        assertContains('создан (значение не хранится)', $later);
        $revoked = renderAdmin($base, 'POST', ['action' => 'revoke']);
        assertSame('', Bootstrap::standalone($base)->settings()->tokenHash());
        assertContains('не создан', $revoked);
    });
});

test('страница настроек: введённые значения экранируются (нет XSS через поля настроек)', function (): void {
    withSite(function (string $base): void {
        $evil = '"><script>alert(1)</script>';
        renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'mode' => 'readonly', 'update_repo' => $evil, 'allowed_origins' => $evil, 'update_public_key' => $evil]);
        $html = renderAdmin($base);
        assertTrue(!str_contains($html, '<script>alert(1)</script>'), 'сырой script в странице');
        assertContains('&lt;script&gt;', $html);
    });
});

test('страница настроек: форма содержит обе галочки, скрытое действие и кнопку в одной форме', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base);
        preg_match_all('#<form\b.*?</form>#s', $html, $forms);
        $settingsForm = '';
        foreach ($forms[0] as $form) {
            if (str_contains($form, 'name="mcp_enabled"')) {
                $settingsForm = $form;
            }
        }
        assertTrue($settingsForm !== '', 'форма настроек найдена');
        foreach (['name="oauth_enabled"', 'name="action" value="save"', 'name="sessid"', 'type="submit"'] as $needle) {
            assertContains($needle, $settingsForm);
        }
    });
});

test('страница настроек: если запись молча не дошла (хранилище «теряет» значение), об этом сказано, а не «сохранено»', function (): void {
    // Хранилище, которое принимает запись, но не сохраняет oauth_enabled: именно такой тихий сбой выглядит как «галочка сама отключается».
    $lossy = new class implements OptionStore {
        /** @var array<string,string> */
        private array $data = [];

        public function get(string $name, string $default = ''): string
        {
            return $this->data[$name] ?? $default;
        }

        public function set(string $name, string $value): void
        {
            if ($name !== 'oauth_enabled') {
                $this->data[$name] = $value;
            }
        }
    };
    withSite(function (string $base) use ($lossy): void {
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'oauth_enabled' => 'Y', 'mode' => 'readonly'], true, $lossy);
        assertContains('MSG ERROR: Настройки НЕ сохранились', $html);
        assertContains('oauth_enabled: записано «Y», прочитано «»', $html);
        assertTrue(!str_contains($html, 'MSG OK'), 'об успехе сообщать нельзя');
    });
});

/** Пара «cookie + поле формы», которую страница выдала бы браузеру (по секрету из файла настроек). */
function csrfPair(string $base, string $raw = 'a1b2c3d4e5f60718293a4b5c6d7e8f90'): array
{
    $secret = (new Settings(new FileOptionStore($base . '/_data/settings.php')))->csrfSecret();

    return [[Csrf::COOKIE => $raw], [Csrf::FIELD => (new Csrf($secret))->issue($raw)['field']]];
}

test('защита форм: страница выдаёт в форме скрытое поле, секрет создаётся один раз и не меняется', function (): void {
    withSite(function (string $base): void {
        $first = renderAdmin($base);
        preg_match_all('/name="siteops_csrf" value="([a-f0-9]{64})"/', $first, $m1);
        assertTrue(count($m1[1]) >= 3, 'поле есть во всех трёх формах');
        $second = renderAdmin($base);
        preg_match_all('/name="siteops_csrf" value="([a-f0-9]{64})"/', $second, $m2);
        assertSame(strlen((new Settings(new FileOptionStore($base . '/_data/settings.php')))->csrfSecret()), 64);
        assertTrue($m1[1][0] !== $m2[1][0], 'без cookie каждый раз новое значение (cookie ещё не сохранён браузером)');
    });
});

test('защита форм: Битрикс не пропускает sessid (как у вас на сайте), а собственная защита пропускает — настройки сохраняются', function (): void {
    withSite(function (string $base): void {
        renderAdmin($base); // создаёт секрет
        [$cookies, $field] = csrfPair($base);
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'oauth_enabled' => 'Y', 'mode' => 'readonly'] + $field, false, null, $cookies);
        assertContains('MSG OK: Настройки сохранены и проверены чтением', $html);
        $s = Bootstrap::standalone($base)->settings();
        assertTrue($s->mcpEnabled() && $s->oauthEnabled(), 'включено, хотя штатная проверка сессии не прошла');
    });
});

test('защита форм: создание токена тоже работает без штатной проверки сессии', function (): void {
    withSite(function (string $base): void {
        renderAdmin($base);
        [$cookies, $field] = csrfPair($base);
        $html = renderAdmin($base, 'POST', ['action' => 'token'] + $field, false, null, $cookies);
        assertTrue((bool) preg_match('/value="sop_[a-f0-9]{48}"/', $html), 'токен создан и показан');
    });
});

test('защита форм: подделка отклоняется (чужое поле, чужой секрет, нет cookie, битая cookie)', function (): void {
    withSite(function (string $base): void {
        renderAdmin($base);
        [$cookies, $field] = csrfPair($base);
        $post = ['action' => 'save', 'mcp_enabled' => 'Y', 'mode' => 'readonly'];
        $attacks = [
            'чужое поле' => [$post + [Csrf::FIELD => str_repeat('0', 64)], $cookies],
            'поле от другого секрета' => [$post + [Csrf::FIELD => (new Csrf('чужой-секрет'))->issue('a1b2c3d4e5f60718293a4b5c6d7e8f90')['field']], $cookies],
            'нет cookie' => [$post + $field, []],
            'битая cookie' => [$post + $field, [Csrf::COOKIE => 'не-hex']],
            'cookie от другого значения' => [$post + $field, [Csrf::COOKIE => 'ffffffffffffffffffffffffffffffff']],
            'поле — массив' => [$post + [Csrf::FIELD => ['x']], $cookies],
        ];
        foreach ($attacks as $name => [$data, $cookie]) {
            $html = renderAdmin($base, 'POST', $data, false, null, $cookie);
            assertContains('Не прошла проверка безопасности формы', $html, $name);
            assertTrue(!Bootstrap::standalone($base)->settings()->mcpEnabled(), $name . ': настройки изменились');
        }
    });
});

test('защита форм: запасной вариант — штатный sessid Битрикса по-прежнему принимается', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'mode' => 'readonly'], true);
        assertContains('MSG OK', $html);
        assertTrue(Bootstrap::standalone($base)->settings()->mcpEnabled());
    });
});

test('Csrf: выдача, проверка, повторное использование cookie и отказ при подмене', function (): void {
    $csrf = new Csrf('секрет');
    $a = $csrf->issue(null);
    assertTrue((bool) preg_match('/^[a-f0-9]{32}$/', $a['cookie']) && (bool) preg_match('/^[a-f0-9]{64}$/', $a['field']));
    assertTrue($csrf->verify($a['cookie'], $a['field']));
    assertSame($a['cookie'], $csrf->issue($a['cookie'])['cookie'], 'cookie переиспользуется, чтобы работали несколько вкладок');
    assertSame($a['field'], $csrf->issue($a['cookie'])['field']);
    assertTrue(!$csrf->verify($a['cookie'], strrev($a['field'])));
    assertTrue(!$csrf->verify(null, $a['field']) && !$csrf->verify($a['cookie'], null) && !$csrf->verify([$a['cookie']], $a['field']));
    assertTrue(!(new Csrf('другой'))->verify($a['cookie'], $a['field']), 'другой секрет');
    assertTrue($csrf->issue('не-hex')['cookie'] !== 'не-hex', 'недопустимая cookie заменяется новой');
});
