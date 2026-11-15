<?php

declare(strict_types=1);

/**
 * Страница согласия OAuth. Подключается из site.ops/oauth/authorize/index.php.
 * Ожидает $siteops (Bootstrap), подключённый prolog_admin_before.php и проверенное право администратора.
 *
 * Защита:
 *  - код выдаётся только в ответ на POST с токеном сессии Битрикса (GET никогда ничего не разрешает);
 *  - параметры OAuth берутся только из адреса (GET), адрес возврата проверяется на точное совпадение с зарегистрированным;
 *  - страница не встраивается во фреймы (защита от кликджекинга).
 */

use OrgBox\SiteOps\Audit\BitrixAuditLogger;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\OAuth\AuthorizeException;

/** @var Bootstrap $siteops */
global $APPLICATION, $USER;

header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$h = static fn (string $value): string => htmlspecialcharsbx($value);
$settings = $siteops->settings();

$page = static function (string $title, string $html) use ($APPLICATION): void {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    $APPLICATION->SetTitle($title);
    echo $html;
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    exit;
};

if (!$siteops->oauthAvailable() || !$settings->mcpEnabled() || !$settings->oauthEnabled()) {
    http_response_code(404);
    $page('Подключение недоступно', '<p>Подключение через OAuth выключено в настройках orgBox SiteOps.</p>');
}

$oauth = $siteops->oauthServer();
try {
    $request = $oauth->validateAuthorizationRequest($_GET);
} catch (AuthorizeException $exception) {
    $back = $oauth->errorRedirect($exception);
    if ($back !== null) {
        header('Location: ' . $back, true, 302);
        exit;
    }
    http_response_code(400);
    $page('Ошибка подключения', '<p>' . $h($exception->getMessage()) . '</p>');
}

$client = $request['client'];
$host = (string) (parse_url($request['redirect_uri'], PHP_URL_HOST) ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $decision = (string) ($_POST['decision'] ?? '');
    if ($decision === 'allow') {
        (new BitrixAuditLogger())->log('oauth.approve', [
            'client_id' => $client['client_id'], 'client_name' => $client['name'], 'redirect_host' => $host, 'user_id' => (int) $USER->GetID(),
        ]);
        header('Location: ' . $oauth->approve($request, (int) $USER->GetID()), true, 302);
        exit;
    }
    if ($decision === 'deny') {
        header('Location: ' . $oauth->deny($request), true, 302);
        exit;
    }
}

$mode = $settings->writesEnabled() ? 'чтение и запись' : 'только чтение';
$iblocks = $settings->allowedIblocks();
$scope = $iblocks === [] ? 'все инфоблоки (запись запрещена)' : 'инфоблоки ' . implode(', ', $iblocks);
$action = (string) $_SERVER['REQUEST_URI'];

ob_start();
?>
<div style="max-width:640px">
    <p><b><?=$h($client['name'])?></b> просит доступ к данным этого сайта через MCP.</p>
    <table class="adm-detail-content-table edit-table">
        <tr><td width="35%">Адрес возврата:</td><td><b><?=$h($host)?></b><br><small><?=$h($request['redirect_uri'])?></small></td></tr>
        <tr><td>Режим сайта:</td><td><?=$h($mode)?></td></tr>
        <tr><td>Доступно:</td><td><?=$h($scope)?></td></tr>
        <tr><td>Вы вошли как:</td><td><?=$h((string) $USER->GetLogin())?></td></tr>
    </table>
    <p><small>Название клиент указывает сам. Разрешайте, только если подключение начали вы и адрес возврата вам знаком.
    Права доступа определяются настройками сайта (режим и список инфоблоков); подключение не может их расширить.
    Отозвать доступ можно на странице настроек SiteOps.</small></p>
    <form method="post" action="<?=$h($action)?>">
        <?=bitrix_sessid_post()?>
        <button class="adm-btn-save" name="decision" value="allow" type="submit">Разрешить</button>
        <button class="adm-btn" name="decision" value="deny" type="submit">Отклонить</button>
    </form>
</div>
<?php
$page('Подключение ИИ-ассистента', (string) ob_get_clean());
