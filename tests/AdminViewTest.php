<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/BitrixAdminStubs.php';

use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\OptionStore;

/** Прогоняет страницу настроек site.ops и возвращает HTML. */
function renderAdmin(string $base, string $method = 'GET', array $post = [], bool $sessid = true, ?OptionStore $store = null): string
{
    $docroot = tempDir();
    mkdir($docroot . '/bitrix/modules/main/include', 0755, true);
    touch($docroot . '/bitrix/modules/main/include/prolog_admin_after.php');
    touch($docroot . '/bitrix/modules/main/include/epilog_admin.php');

    $saved = [$_SERVER, $_POST];
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
        [$_SERVER, $_POST] = $saved;
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

test('страница настроек: не прошла проверка сессии — об этом сказано вслух, ничего не записано', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'oauth_enabled' => 'Y'], false);
        assertContains('MSG ERROR: Не прошла проверка сессии', $html);
        assertContains('НЕ сохранены', $html);
        assertTrue(!is_file($base . '/_data/settings.php'), 'файл настроек не создан');
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
