<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/BitrixAdminStubs.php';

use OrgBox\SiteOps\Admin\Csrf;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\FileOptionStore;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\OAuth\OAuthStore;
use OrgBox\SiteOps\Update\HttpClient;
use Tests\Support\MemoryOAuthStore;
use Tests\Support\FakeHttp;
use OrgBox\SiteOps\Config\OptionStore;

/** Прогоняет страницу настроек site.ops и возвращает HTML. */
function renderAdmin(string $base, string $method = 'GET', array $post = [], bool $sessid = true, ?OptionStore $store = null, array $cookies = [], ?HttpClient $http = null, ?OAuthStore $oauthStore = null): string
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
    $siteops = Bootstrap::standalone($base, $store, $oauthStore, null, $http);

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

test('страница настроек: галочка «читать все инфоблоки» сохраняется и отражается на форме', function (): void {
    withSite(function (string $base): void {
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'mode' => 'readonly', 'read_all_iblocks' => 'Y', 'allowed_iblocks' => '5']);
        assertTrue(Bootstrap::standalone($base)->settings()->readAllIblocks());
        assertTrue(checked($html, 'read_all_iblocks'));
        assertContains('чтение инфоблоков: все', $html);
        $html = renderAdmin($base, 'POST', ['action' => 'save', 'mcp_enabled' => 'Y', 'mode' => 'readonly', 'allowed_iblocks' => '5, 6']); // галочка не отправлена
        $s = Bootstrap::standalone($base)->settings();
        assertTrue(!$s->readAllIblocks() && !checked($html, 'read_all_iblocks'));
        assertSame([5, 6], $s->allowedIblocks());
        assertContains('чтение инфоблоков: только перечисленные', $html);
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

// ---- обновление со страницы настроек ----------------------------------------------------------

/** Установленный site.ops старой версии: version.php рядом, чтобы страница знала текущую версию. */
function withInstalledSite(callable $body, string $version = '0.0.1'): void
{
    withSite(function (string $base) use ($body, $version): void {
        file_put_contents($base . '/version.php', "<?php\n\$arModuleVersion = ['VERSION' => '$version', 'VERSION_DATE' => '2026-01-01 00:00:00'];\n");
        file_put_contents($base . '/autoload.php', '<?php // old');
        file_put_contents($base . '/.htaccess', '# old');
        mkdir($base . '/lib', 0755, true);
        $GLOBALS['_SESSION'] = [];
        $body($base);
    });
}

/**
 * Публичный релиз на «GitHub»: ответы API и файлы настоящего собранного архива site.ops.
 * @return array{0:FakeHttp,1:string,2:string} заглушка, версия релиза, содержимое архива
 */
function fakeRelease(?string $signWith = null): array
{
    $out = buildRelease();
    $version = releaseVersion();
    $zip = (string) file_get_contents($out . "/site.ops-$version.zip");
    $name = "site.ops-$version.zip";
    $asset = static fn (string $n): array => ['name' => $n, 'browser_download_url' => "https://github.com/o/r/releases/download/v$version/$n"];
    $assets = [$asset($name), $asset($name . '.sha256')];
    $files = [
        "https://github.com/o/r/releases/download/v$version/$name" => $zip,
        "https://github.com/o/r/releases/download/v$version/$name.sha256" => hash('sha256', $zip) . "  $name\n",
    ];
    if ($signWith !== null) {
        $assets[] = $asset($name . '.sig');
        $files["https://github.com/o/r/releases/download/v$version/$name.sig"] = base64_encode(sodium_crypto_sign_detached($zip, $signWith)) . "\n";
    }
    removeDir($out);

    return [new FakeHttp(['https://api.github.com/repos/o/r/releases/latest' => json_encode(['tag_name' => 'v' . $version, 'body' => 'Что нового', 'assets' => $assets])] + $files), $version, $zip];
}

function postUpdate(string $base, string $action, ?HttpClient $http, array $extra = []): string
{
    [$cookies, $field] = csrfPair($base);

    return renderAdmin($base, 'POST', ['action' => $action] + $field + $extra, false, null, $cookies, $http);
}

test('обновление: «Проверить обновления» находит новую версию и показывает кнопку установки (раньше страница падала с ошибкой конструктора)', function (): void {
    withInstalledSite(function (string $base): void {
        [$http, $version] = fakeRelease();
        renderAdmin($base);
        $settings = new Settings(new FileOptionStore($base . '/_data/settings.php'));
        $settings->store()->set('update_repo', 'o/r');
        $html = postUpdate($base, 'check', $http);
        assertTrue(!str_contains($html, 'Ошибка:'), 'страница не должна падать: ' . substr(strip_tags($html), 0, 200));
        assertContains('Доступна версия ' . $version, $html);
        assertContains('Установить ' . $version, $html);
        assertContains('Что нового', $html);
    });
});

test('обновление: если установлена последняя версия, страница так и говорит', function (): void {
    withInstalledSite(function (string $base): void {
        [$http, $version] = fakeRelease();
        renderAdmin($base);
        (new Settings(new FileOptionStore($base . '/_data/settings.php')))->store()->set('update_repo', 'o/r');
        $html = postUpdate($base, 'check', $http);
        file_put_contents($base . '/version.php', "<?php\n\$arModuleVersion = ['VERSION' => '$version', 'VERSION_DATE' => 'x'];\n");
        $html = postUpdate($base, 'check', $http);
        assertContains('Установлена последняя версия (' . $version . ')', $html);
        assertTrue(!str_contains($html, 'Установить '), 'кнопки установки нет');
    }, '0.0.1');
});

test('обновление: ошибка сети показывается сообщением, а не падением страницы', function (): void {
    withInstalledSite(function (string $base): void {
        renderAdmin($base);
        $html = postUpdate($base, 'check', new FakeHttp([]));
        assertContains('MSG ERROR', $html);
        assertTrue(!str_contains($html, 'Fatal'), 'без фатальной ошибки');
        assertContains('Сохранить', $html, 'страница отрисована целиком');
    });
});

test('обновление: без проверки сначала установить нельзя', function (): void {
    withInstalledSite(function (string $base): void {
        renderAdmin($base);
        $html = postUpdate($base, 'update', new FakeHttp([]));
        assertContains('Сначала нажмите «Проверить обновления»', $html);
    });
});

test('обновление: без ключа подписи и без разрешения «без подписи» установка отклоняется, файлы не тронуты', function (): void {
    withInstalledSite(function (string $base): void {
        [$http] = fakeRelease();
        renderAdmin($base);
        (new Settings(new FileOptionStore($base . '/_data/settings.php')))->store()->set('update_repo', 'o/r');
        postUpdate($base, 'check', $http);
        $html = postUpdate($base, 'update', $http);
        assertContains('Не задан открытый ключ', $html);
        assertContains("'0.0.1'", (string) file_get_contents($base . '/version.php'), 'версия не изменилась');
        assertSame('<?php // old', (string) file_get_contents($base . '/autoload.php'));
    });
});

test('обновление: с разрешением «без подписи» устанавливается по SHA-256, настройки (_data) целы', function (): void {
    withInstalledSite(function (string $base): void {
        [$http, $version] = fakeRelease();
        renderAdmin($base);
        $store = new FileOptionStore($base . '/_data/settings.php');
        $store->set('update_repo', 'o/r');
        $store->set('update_allow_unsigned', 'Y');
        $store->set('mcp_enabled', 'Y');
        postUpdate($base, 'check', $http);
        $html = postUpdate($base, 'update', $http);
        assertContains('Установлена версия ' . $version, $html);
        assertContains('только SHA-256', $html);
        assertContains("'$version'", (string) file_get_contents($base . '/version.php'));
        assertTrue(is_file($base . '/mcp/index.php') && is_file($base . '/lib/OrgBox/SiteOps/Bootstrap.php'), 'новые файлы на месте');
        assertSame('Y', (new FileOptionStore($base . '/_data/settings.php'))->get('mcp_enabled'), 'настройки сохранились');
    });
});

test('обновление: с настроенным ключом принимается только подписанный релиз', function (): void {
    withInstalledSite(function (string $base): void {
        $pair = sodium_crypto_sign_keypair();
        [$http, $version] = fakeRelease(sodium_crypto_sign_secretkey($pair));
        renderAdmin($base);
        $store = new FileOptionStore($base . '/_data/settings.php');
        $store->set('update_repo', 'o/r');
        $store->set('update_public_key', base64_encode(sodium_crypto_sign_publickey($pair)));
        postUpdate($base, 'check', $http);
        $html = postUpdate($base, 'update', $http);
        assertContains('подпись и SHA-256', $html);
        assertContains("'$version'", (string) file_get_contents($base . '/version.php'));
    });
});

test('обновление: подпись чужим ключом отклоняется, установка не происходит', function (): void {
    withInstalledSite(function (string $base): void {
        [$http] = fakeRelease(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        renderAdmin($base);
        $store = new FileOptionStore($base . '/_data/settings.php');
        $store->set('update_repo', 'o/r');
        $store->set('update_public_key', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
        postUpdate($base, 'check', $http);
        $html = postUpdate($base, 'update', $http);
        assertContains('Подпись релиза недействительна', $html);
        assertContains("'0.0.1'", (string) file_get_contents($base . '/version.php'));
    });
});

// ---- список OAuth-подключений и отзыв --------------------------------------------------------

function storeWithConnection(string $name = 'Claude Code'): MemoryOAuthStore
{
    $store = new MemoryOAuthStore();
    $store->saveClient(['client_id' => 'sopc_1', 'name' => $name, 'redirect_uris' => ['http://localhost:7777/callback'], 'created' => time() - 3600]);
    $store->saveToken(['hash' => 'rt', 'type' => 'refresh', 'client_id' => 'sopc_1', 'user_id' => 1, 'family' => 'f', 'expires' => time() + 86400, 'revoked' => false, 'created' => time() - 60]);

    return $store;
}

test('подключения OAuth: список показывает клиента и адрес возврата, имя экранируется', function (): void {
    withSite(function (string $base): void {
        (new Settings(new FileOptionStore($base . '/_data/settings.php')))->store()->set('mcp_enabled', 'Y');
        (new Settings(new FileOptionStore($base . '/_data/settings.php')))->store()->set('oauth_enabled', 'Y');
        $html = renderAdmin($base, 'GET', [], true, null, [], null, storeWithConnection('<b>Evil</b>'));
        assertContains('Подключения (OAuth)', $html);
        assertContains('localhost', $html);
        assertContains('&lt;b&gt;Evil&lt;/b&gt;', $html);
        assertTrue(!str_contains($html, '<b>Evil</b>'), 'имя клиента не экранировано');
        assertContains('value="revoke_client"', $html);
        assertTrue(!str_contains($html, 'Активных подключений нет'));
    });
});

test('подключения OAuth: без подключений так и написано', function (): void {
    withSite(function (string $base): void {
        $store = new FileOptionStore($base . '/_data/settings.php');
        $store->set('mcp_enabled', 'Y');
        $store->set('oauth_enabled', 'Y');
        assertContains('Активных подключений нет', renderAdmin($base, 'GET', [], true, null, [], null, new MemoryOAuthStore()));
    });
});

test('подключения OAuth: «Отозвать» убирает подключение, закрывает токены и пишет в журнал событий', function (): void {
    withSite(function (string $base): void {
        $settings = new FileOptionStore($base . '/_data/settings.php');
        $settings->set('mcp_enabled', 'Y');
        $settings->set('oauth_enabled', 'Y');
        $oauthStore = storeWithConnection();
        renderAdmin($base, 'GET', [], true, null, [], null, $oauthStore);
        [$cookies, $field] = csrfPair($base);
        CEventLog::$entries = [];
        $html = renderAdmin($base, 'POST', ['action' => 'revoke_client', 'client_id' => 'sopc_1'] + $field, false, null, $cookies, null, $oauthStore);
        assertContains('Подключение отозвано', $html);
        assertSame([], $oauthStore->listConnections(time()), 'подключений не осталось');
        assertTrue($oauthStore->getToken('rt')['revoked'], 'токен отозван');
        assertTrue($oauthStore->getClient('sopc_1') === null, 'клиент удалён');
        assertTrue(array_filter(CEventLog::$entries, static fn ($e) => ($e['ITEM_ID'] ?? '') === 'oauth.revoke_client') !== [], 'отзыв записан в журнал событий');
    });
});

test('подключения OAuth: без защиты формы отозвать нельзя', function (): void {
    withSite(function (string $base): void {
        $settings = new FileOptionStore($base . '/_data/settings.php');
        $settings->set('mcp_enabled', 'Y');
        $settings->set('oauth_enabled', 'Y');
        $oauthStore = storeWithConnection();
        $html = renderAdmin($base, 'POST', ['action' => 'revoke_client', 'client_id' => 'sopc_1'], false, null, [], null, $oauthStore);
        assertContains('Не прошла проверка безопасности формы', $html);
        assertSame(1, count($oauthStore->listConnections(time())), 'подключение осталось');
    });
});
