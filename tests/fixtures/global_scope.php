<?php

declare(strict_types=1);

/**
 * Прогон страницы администратора на ВЕРХНЕМ уровне скрипта, как это делает настоящая точка входа Битрикса.
 * Файлы пролога здесь ведут себя как настоящие: prolog_admin_before создаёт глобальный объект страницы, а prolog_admin_after
 * использует его без `global`. Если страница подключит пролог из функции, переменной не будет и прогон завершится ошибкой.
 *
 * Использование: php global_scope.php <authorize|settings> <папка-site.ops> <временный-docroot>
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Support/BitrixAdminStubs.php';

use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\FileOptionStore;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\OAuth\OAuthServer;
use Tests\Support\MemoryOAuthStore;

[, $which, $base, $docroot] = $argv;

$include = $docroot . '/bitrix/modules/main/include';
@mkdir($include, 0755, true);
file_put_contents($include . '/prolog_admin_before.php', '<?php $adminPage = new class { public function Init(): void {} };');
file_put_contents($include . '/prolog_admin_after.php', '<?php $adminPage->Init(); echo "[ADMIN-PROLOG]";');
file_put_contents($include . '/epilog_admin.php', '<?php echo "[ADMIN-EPILOG]";');

$_SERVER['DOCUMENT_ROOT'] = $docroot;
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$_COOKIE = [];
$APPLICATION = new TestApplication();
$USER = new TestUser();

require $include . '/prolog_admin_before.php'; // верхний уровень: создаёт глобальный $adminPage

$settings = new Settings(new FileOptionStore($base . '/_data/settings.php'));
$settings->store()->set('mcp_enabled', 'Y');
$settings->store()->set('oauth_enabled', 'Y');

final class RunnerExit extends RuntimeException
{
}

$oauthStore = new MemoryOAuthStore();
$_GET = [];
if ($which === 'authorize') {
    $oauth = new OAuthServer($oauthStore, 'https://shop.example');
    $client = $oauth->register(['client_name' => 'Claude', 'redirect_uris' => ['http://localhost:7777/callback']]);
    $_GET = [
        'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'http://localhost:7777/callback',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'v', true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'state' => 's',
    ];
}
$_SERVER['REQUEST_URI'] = '/site.ops/' . $which . '/?' . http_build_query($_GET);

$siteops = Bootstrap::standalone($base, null, $oauthStore, static function (?string $location): never {
    throw new RunnerExit((string) $location);
});

ob_start();
try {
    require __DIR__ . '/../../modules/orgbox.siteops/lib/OrgBox/SiteOps/Admin/' . $which . '.view.php';
} catch (RunnerExit) {
    // нормальное завершение страницы
}
fwrite(STDOUT, (string) ob_get_clean());
