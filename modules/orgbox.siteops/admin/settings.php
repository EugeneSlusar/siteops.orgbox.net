<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Update\CurlHttpClient;
use OrgBox\SiteOps\Update\ReleaseVerifier;
use OrgBox\SiteOps\Update\Updater;
use OrgBox\SiteOps\Update\UpdateException;
use OrgBox\SiteOps\Update\ZipInstaller;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $APPLICATION, $USER;
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ разрешён только администратору.');
}
if (!Loader::includeModule('orgbox.siteops')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage('Модуль orgBox: SiteOps не установлен.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$settings = Bootstrap::settings();
$store = $settings->store();
$messages = [];
$newToken = '';
$release = null;

$h = static fn (string $value): string => htmlspecialcharsbx($value);
$makeUpdater = static function () use ($settings): Updater {
    $moduleDir = Bootstrap::moduleDir();
    $verifier = new ReleaseVerifier($settings->updatePublicKey(), $settings->allowUnsignedUpdates());
    $afterApply = static function (string $dir): void {
        // Обновить копии точек входа и страниц админки, лежащие вне папки модуля.
        CopyDirFiles($dir . '/install/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        CopyDirFiles($dir . '/install/tools', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/tools', true, true);
    };

    return new Updater(
        new CurlHttpClient(),
        $verifier,
        new ZipInstaller($afterApply),
        $settings->updateRepository(),
        $settings->updateGithubToken(),
        $moduleDir,
        $_SERVER['DOCUMENT_ROOT'] . '/upload/orgbox_siteops/updates',
        Bootstrap::version(),
    );
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $store->set('mcp_enabled', isset($_POST['mcp_enabled']) ? 'Y' : 'N');
            $store->set('mode', ($_POST['mode'] ?? '') === Settings::MODE_READWRITE ? Settings::MODE_READWRITE : Settings::MODE_READONLY);
            $store->set('allowed_iblocks', implode(',', Settings::intList((string) ($_POST['allowed_iblocks'] ?? ''))));
            $store->set('default_iblock_id', (string) max(0, (int) ($_POST['default_iblock_id'] ?? 0)));
            $store->set('allowed_ips', implode(',', Settings::stringList((string) ($_POST['allowed_ips'] ?? ''))));
            $store->set('allowed_origins', implode(',', Settings::stringList((string) ($_POST['allowed_origins'] ?? ''))));
            $store->set('rate_limit', (string) max(1, min(1000, (int) ($_POST['rate_limit'] ?? 60))));
            $store->set('update_repo', trim((string) ($_POST['update_repo'] ?? 'EugeneSlusar/siteops.orgbox.net')));
            $store->set('update_public_key', trim((string) ($_POST['update_public_key'] ?? '')));
            $store->set('update_allow_unsigned', isset($_POST['update_allow_unsigned']) ? 'Y' : 'N');
            if (trim((string) ($_POST['update_github_token'] ?? '')) !== '') {
                $store->set('update_github_token', trim((string) $_POST['update_github_token']));
            }
            $messages[] = ['OK', 'Настройки сохранены.'];
        } elseif ($action === 'token') {
            $newToken = $settings->generateToken();
            $messages[] = ['OK', 'Новый токен создан. Прежний токен больше не работает.'];
        } elseif ($action === 'revoke') {
            $settings->revokeToken();
            $messages[] = ['OK', 'Токен отозван.'];
        } elseif ($action === 'check') {
            $release = $makeUpdater()->checkLatest();
            $messages[] = $release === null
                ? ['OK', 'Установлена последняя версия (' . Bootstrap::version() . ').']
                : ['OK', 'Доступна версия ' . $release->version . '.'];
            if ($release !== null) {
                $_SESSION['orgbox_siteops_release'] = serialize($release);
            }
        } elseif ($action === 'update') {
            $stored = isset($_SESSION['orgbox_siteops_release']) ? unserialize((string) $_SESSION['orgbox_siteops_release'], ['allowed_classes' => [\OrgBox\SiteOps\Update\ReleaseInfo::class]]) : null;
            if (!$stored instanceof \OrgBox\SiteOps\Update\ReleaseInfo) {
                throw new UpdateException('Сначала нажмите «Проверить обновления».');
            }
            $method = $makeUpdater()->install($stored);
            unset($_SESSION['orgbox_siteops_release']);
            $messages[] = ['OK', 'Установлена версия ' . $stored->version . ' (проверка: ' . ($method === 'signed' ? 'подпись и SHA-256' : 'только SHA-256') . '). Обновите страницу.'];
        }
    } catch (UpdateException $exception) {
        $messages[] = ['ERROR', $exception->getMessage()];
    } catch (\Throwable $exception) {
        \OrgBox\SiteOps\Audit\BitrixAuditLogger::logError($exception);
        $messages[] = ['ERROR', 'Ошибка: ' . $exception->getMessage()];
    }
    $settings = Bootstrap::settings();
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$mcpUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/bitrix/tools/orgbox_siteops/mcp.php';

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('orgBox: SiteOps — MCP-сервер');
foreach ($messages as [$type, $text]) {
    CAdminMessage::ShowMessage(['MESSAGE' => $text, 'TYPE' => $type]);
}
$action = $APPLICATION->GetCurPageParam('', [], false);
?>
<h3>Подключение</h3>
<table class="adm-detail-content-table edit-table">
    <tr><td width="35%">Версия модуля:</td><td><b><?=$h(Bootstrap::version())?></b></td></tr>
    <tr><td>Адрес MCP-сервера:</td><td><input type="text" readonly size="80" value="<?=$h($mcpUrl)?>" onclick="this.select()"></td></tr>
    <tr><td>Токен доступа:</td><td>
        <?php if ($newToken !== ''): ?>
            <input type="text" readonly size="80" value="<?=$h($newToken)?>" onclick="this.select()"><br>
            <b>Скопируйте токен сейчас: он показывается один раз, на сайте хранится только его хэш.</b>
        <?php else: ?>
            <?=$settings->tokenHash() !== '' ? 'создан (значение не хранится)' : 'не создан'?>
        <?php endif; ?>
        <form method="post" action="<?=$h($action)?>" style="margin-top:8px">
            <?=bitrix_sessid_post()?>
            <button class="adm-btn" name="action" value="token" onclick="return confirm('Создать новый токен? Прежний перестанет работать.')">Создать новый токен</button>
            <button class="adm-btn" name="action" value="revoke" onclick="return confirm('Отозвать токен?')">Отозвать</button>
        </form>
    </td></tr>
</table>

<h3>Настройки</h3>
<form method="post" action="<?=$h($action)?>">
    <?=bitrix_sessid_post()?>
    <input type="hidden" name="action" value="save">
    <table class="adm-detail-content-table edit-table">
        <tr><td width="35%">MCP-сервер:</td><td><label><input type="checkbox" name="mcp_enabled" value="Y"<?=$settings->mcpEnabled() ? ' checked' : ''?>> включён</label></td></tr>
        <tr><td>Режим:</td><td>
            <label><input type="radio" name="mode" value="readonly"<?=!$settings->writesEnabled() ? ' checked' : ''?>> только чтение (рекомендуется)</label><br>
            <label><input type="radio" name="mode" value="readwrite"<?=$settings->writesEnabled() ? ' checked' : ''?>> чтение и запись (удаления нет; изменения пишутся в журнал событий)</label>
        </td></tr>
        <tr><td>ID инфоблоков:</td><td><input type="text" name="allowed_iblocks" size="40" value="<?=$h(implode(', ', $settings->allowedIblocks()))?>"><br><small>Через запятую. Пусто — чтение всех инфоблоков. <b>Запись разрешена только для перечисленных.</b></small></td></tr>
        <tr><td>Инфоблок по умолчанию (SEO):</td><td><input type="number" name="default_iblock_id" min="0" value="<?=(int) $settings->defaultIblockId()?>"></td></tr>
        <tr><td>Разрешённые IP:</td><td><input type="text" name="allowed_ips" size="40" value="<?=$h(implode(', ', $settings->allowedIps()))?>"><br><small>Пусто — любые. Если сайт за прокси или CDN, проверка по IP работать не будет.</small></td></tr>
        <tr><td>Разрешённые Origin:</td><td><input type="text" name="allowed_origins" size="40" value="<?=$h(implode(', ', $settings->allowedOrigins()))?>"><br><small>Для браузерных клиентов; обычным клиентам MCP не нужны. Пусто — запросы с Origin отклоняются.</small></td></tr>
        <tr><td>Запросов в минуту с IP:</td><td><input type="number" name="rate_limit" min="1" max="1000" value="<?=(int) $settings->rateLimitPerMinute()?>"></td></tr>
    </table>

    <h3>Обновления</h3>
    <table class="adm-detail-content-table edit-table">
        <tr><td width="35%">Репозиторий релизов:</td><td><input type="text" name="update_repo" size="40" value="<?=$h($settings->updateRepository())?>"></td></tr>
        <tr><td>Открытый ключ подписи (Ed25519, base64):</td><td><input type="text" name="update_public_key" size="70" value="<?=$h($settings->updatePublicKey())?>"><br><small>Без ключа обновления не устанавливаются, если не разрешены без подписи.</small></td></tr>
        <tr><td>Без подписи:</td><td><label><input type="checkbox" name="update_allow_unsigned" value="Y"<?=$settings->allowUnsignedUpdates() ? ' checked' : ''?>> разрешить обновления, проверяемые только по SHA-256 (менее безопасно)</label></td></tr>
        <tr><td>Токен GitHub (для приватного репозитория):</td><td><input type="password" name="update_github_token" size="50" value="" autocomplete="new-password" placeholder="<?=$settings->updateGithubToken() !== '' ? 'задан; введите новый, чтобы заменить' : 'не задан'?>"><br><small>Нужен только доступ на чтение содержимого репозитория. Хранится в настройках модуля.</small></td></tr>
    </table>
    <input class="adm-btn-save" type="submit" value="Сохранить">
</form>

<form method="post" action="<?=$h($action)?>" style="margin-top:16px">
    <?=bitrix_sessid_post()?>
    <button class="adm-btn" name="action" value="check">Проверить обновления</button>
    <?php if ($release !== null): ?>
        <button class="adm-btn adm-btn-green" name="action" value="update" onclick="return confirm('Установить версию <?=$h($release->version)?>? Текущая версия сохранится в резервной копии.')">Установить <?=$h($release->version)?></button>
        <?php if ($release->notes !== ''): ?><pre style="white-space:pre-wrap;max-width:900px"><?=$h($release->notes)?></pre><?php endif; ?>
    <?php endif; ?>
</form>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
