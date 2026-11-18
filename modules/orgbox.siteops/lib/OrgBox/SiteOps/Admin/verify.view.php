<?php

declare(strict_types=1);

/**
 * Страница подтверждения кода устройства (RFC 8628). Подключается из site.ops/oauth/verify/index.php.
 * Ожидает $siteops (Bootstrap), подключённый prolog_admin_before.php и проверенное право администратора.
 *
 * Защита:
 *  - доступ подтверждается только POST-запросом с проверкой формы (GET по ссылке с кодом ничего не разрешает);
 *  - код вводится или приходит в ссылке, но решение принимается по коду из скрытого поля формы;
 *  - страница не встраивается во фреймы (защита от кликджекинга).
 */

use OrgBox\SiteOps\Admin\Csrf;
use OrgBox\SiteOps\Audit\BitrixAuditLogger;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\OAuth\OAuthServer;

/** @var Bootstrap $siteops */
global $APPLICATION, $USER;

header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$h = static fn (string $value): string => htmlspecialcharsbx($value);
$settings = $siteops->settings();

// Защита форм: собственный CSRF-токен (не зависит от сессии PHP) + штатный sessid Битрикса как запасной вариант.
try {
    $csrfSecret = $settings->csrfSecret();
} catch (\Throwable) {
    $csrfSecret = hash('sha256', 'siteops-fallback|' . (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
}
$csrf = new Csrf($csrfSecret);
$csrfCookie = isset($_COOKIE[Csrf::COOKIE]) && is_string($_COOKIE[Csrf::COOKIE]) ? $_COOKIE[Csrf::COOKIE] : null;
$csrfPair = $csrf->issue($csrfCookie);
Csrf::sendCookie($csrfPair['cookie'], !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$csrfFields = static fn (): string => bitrix_sessid_post()
    . '<input type="hidden" name="' . Csrf::FIELD . '" value="' . htmlspecialcharsbx($csrfPair['field']) . '">';
$csrfPassed = static fn (): bool => $csrf->verify($csrfCookie, $_POST[Csrf::FIELD] ?? null) || check_bitrix_sessid();
/** Что именно не дошло: нужно, чтобы по сообщению об ошибке было видно причину. */
$csrfDiagnosis = static fn (): string => 'в форме sessid Битрикса: ' . (isset($_POST['sessid']) ? 'да' : 'нет')
    . '; cookie PHPSESSID: ' . (isset($_COOKIE['PHPSESSID']) ? 'да' : 'нет')
    . '; cookie защиты форм: ' . ($csrfCookie !== null ? 'да' : 'нет')
    . '; поле защиты форм: ' . (isset($_POST[Csrf::FIELD]) ? 'да' : 'нет');


/** Логика решения без вывода (вывод админки подключается ниже, из глобальной области). @return array{status:int,title:string,html:string} */
$outcome = (static function () use ($siteops, $settings, $csrfPassed, $csrfDiagnosis, $csrfFields, $h): array {
    global $USER;

    if (!$siteops->oauthAvailable() || !$settings->mcpEnabled() || !$settings->oauthEnabled()) {
        return ['status' => 404, 'title' => 'Подключение недоступно', 'html' => '<p>Подключение через OAuth выключено в настройках orgBox SiteOps.</p>'];
    }

    $oauth = $siteops->oauthServer();
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $input = (string) ($isPost ? ($_POST['user_code'] ?? '') : ($_GET['user_code'] ?? ''));
    $action = strtok((string) $_SERVER['REQUEST_URI'], '?') ?: '';

    if ($isPost && !$csrfPassed()) {
        return ['status' => 400, 'title' => 'Подключение не подтверждено', 'html' => '<p>Не прошла проверка безопасности формы: доступ не выдан. Откройте ссылку подключения заново. (' . $h($csrfDiagnosis()) . ')</p>'];
    }

    $pending = $input !== '' ? $oauth->pendingDevice($input) : null;
    $decision = $isPost ? (string) ($_POST['decision'] ?? '') : '';

    if ($pending !== null && in_array($decision, ['allow', 'deny'], true)) {
        $approve = $decision === 'allow';
        if ($oauth->decideDevice($input, $approve, (int) $USER->GetID())) {
            (new BitrixAuditLogger())->log($approve ? 'oauth.device_approve' : 'oauth.device_deny', [
                'client_id' => $pending['client']['client_id'], 'client_name' => $pending['client']['name'], 'user_id' => (int) $USER->GetID(),
            ]);
            $text = $approve
                ? 'Доступ выдан. Вернитесь в клиент MCP: подключение завершится само в течение нескольких секунд.'
                : 'Подключение отклонено. Доступ не выдан.';

            return ['status' => 200, 'title' => 'Готово', 'html' => '<p>' . $h($text) . '</p>'];
        }
    }

    ob_start();
    if ($pending === null) {
        if ($input !== '') {
            echo '<p style="color:#c00"><b>Код не найден, просрочен или уже использован.</b> Запустите подключение в клиенте заново или проверьте код.</p>';
        }
        ?>
<form method="get" action="<?=$h($action)?>" style="max-width:640px">
    <p>Введите код, который показал клиент MCP (ИИ-ассистент):</p>
    <p><input type="text" name="user_code" value="" placeholder="XXXX-XXXX" maxlength="12" autocomplete="off" autofocus style="font-size:20px;letter-spacing:2px;text-transform:uppercase">
    <button class="adm-btn-save" type="submit">Продолжить</button></p>
</form>
<?php
    } else {
        $client = $pending['client'];
        $mode = $settings->writesEnabled() ? 'чтение и запись' : 'только чтение';
        $scope = $settings->accessSummary();
        ?>
<div style="max-width:640px">
    <p><b><?=$h($client['name'])?></b> просит доступ к данным этого сайта через MCP.</p>
    <p style="font-size:28px;letter-spacing:3px;font-weight:bold"><?=$h($pending['user_code'])?></p>
    <p><b>Сверьте код с тем, что показан в клиенте.</b> Если кода там нет или он другой, нажмите «Отклонить»: возможно, подключение начали не вы.</p>
    <table class="adm-detail-content-table edit-table">
        <tr><td width="35%">Запрос пришёл с адреса:</td><td><?=$h($pending['ip'] !== '' ? $pending['ip'] : 'неизвестно')?></td></tr>
        <tr><td>Режим сайта:</td><td><?=$h($mode)?></td></tr>
        <tr><td>Доступно:</td><td><?=$h($scope)?></td></tr>
        <tr><td>Вы вошли как:</td><td><?=$h((string) $USER->GetLogin())?></td></tr>
    </table>
    <p><small>Название клиент указывает сам. Права доступа определяются настройками сайта (режим и список инфоблоков); подключение не может их расширить.
    Отозвать доступ можно на странице настроек SiteOps.</small></p>
    <form method="post" action="<?=$h($action)?>">
        <?=$csrfFields()?>
        <input type="hidden" name="user_code" value="<?=$h($pending['user_code'])?>">
        <button class="adm-btn-save" name="decision" value="allow" type="submit">Разрешить</button>
        <button class="adm-btn" name="decision" value="deny" type="submit">Отклонить</button>
    </form>
</div>
<?php
    }

    return ['status' => 200, 'title' => 'Подключение ИИ-ассистента по коду', 'html' => (string) ob_get_clean()];
})();

http_response_code($outcome['status']);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle($outcome['title']);
echo $outcome['html'];
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
$siteops->finish();
