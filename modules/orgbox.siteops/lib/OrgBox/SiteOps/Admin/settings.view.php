<?php

declare(strict_types=1);

/**
 * Общее представление страницы настроек. Подключается из admin/settings.php (формальный модуль)
 * и из site.ops/admin.php (папка сайта). Ожидает переменную $siteops (OrgBox\SiteOps\Bootstrap),
 * уже подключённый prolog_admin_before.php и проверенное право администратора.
 */

use OrgBox\SiteOps\Admin\Csrf;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Update\Updater;
use OrgBox\SiteOps\Update\UpdateException;

/** @var Bootstrap $siteops */
global $APPLICATION;

$settings = $siteops->settings();
$store = $settings->store();

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

$messages = [];
$newToken = '';
$release = null;

$h = static fn (string $value): string => htmlspecialcharsbx($value);
$makeUpdater = static fn (): Updater => $siteops->updater($settings);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfPassed()) {
    $messages[] = ['ERROR', 'Не прошла проверка безопасности формы: настройки НЕ сохранены. Обновите страницу (Ctrl+F5) и повторите. Если повторяется, пришлите это сообщение разработчику (' . $csrfDiagnosis() . ').'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $store->set('mcp_enabled', isset($_POST['mcp_enabled']) ? 'Y' : 'N');
            if ($siteops->oauthAvailable()) {
                $store->set('oauth_enabled', isset($_POST['oauth_enabled']) ? 'Y' : 'N');
                $publicUrl = rtrim(trim((string) ($_POST['public_url'] ?? '')), '/');
                if ($publicUrl === '' || preg_match('#^https://[A-Za-z0-9.\-]+(:\d{1,5})?$#', $publicUrl)) {
                    $store->set('public_url', $publicUrl);
                } else {
                    $messages[] = ['ERROR', 'Адрес сайта: только https://домен, без пути. Значение не сохранено.'];
                }
            }
            $store->set('mode', ($_POST['mode'] ?? '') === Settings::MODE_READWRITE ? Settings::MODE_READWRITE : Settings::MODE_READONLY);
            $store->set('allowed_iblocks', implode(',', Settings::intList((string) ($_POST['allowed_iblocks'] ?? ''))));
            $store->set('allowed_ips', implode(',', Settings::stringList((string) ($_POST['allowed_ips'] ?? ''))));
            $store->set('allowed_origins', implode(',', Settings::stringList((string) ($_POST['allowed_origins'] ?? ''))));
            $store->set('rate_limit', (string) max(1, min(1000, (int) ($_POST['rate_limit'] ?? 60))));
            $store->set('update_repo', trim((string) ($_POST['update_repo'] ?? 'EugeneSlusar/siteops.orgbox.net')));
            $store->set('update_public_key', trim((string) ($_POST['update_public_key'] ?? '')));
            $store->set('update_allow_unsigned', isset($_POST['update_allow_unsigned']) ? 'Y' : 'N');
            if (trim((string) ($_POST['update_github_token'] ?? '')) !== '') {
                $store->set('update_github_token', trim((string) $_POST['update_github_token']));
            }
            // Проверка чтением новым экземпляром хранилища: «сохранено» только если значения действительно записаны.
            $expected = [
                'mcp_enabled' => isset($_POST['mcp_enabled']) ? 'Y' : 'N',
                'mode' => ($_POST['mode'] ?? '') === Settings::MODE_READWRITE ? Settings::MODE_READWRITE : Settings::MODE_READONLY,
                'allowed_iblocks' => implode(',', Settings::intList((string) ($_POST['allowed_iblocks'] ?? ''))),
            ];
            if ($siteops->oauthAvailable()) {
                $expected['oauth_enabled'] = isset($_POST['oauth_enabled']) ? 'Y' : 'N';
            }
            $fresh = $siteops->optionStore();
            $wrong = [];
            foreach ($expected as $name => $value) {
                if ($fresh->get($name, '') !== $value) {
                    $wrong[] = $name . ': записано «' . $value . '», прочитано «' . $fresh->get($name, '') . '»';
                }
            }
            if ($wrong !== []) {
                $messages[] = ['ERROR', 'Настройки НЕ сохранились (' . implode('; ', $wrong) . '). Хранилище: ' . ($siteops->isStandalone() ? $siteops->settingsFile() : 'b_option') . '. Сообщите это сообщение разработчику.'];
            } else {
                $messages[] = ['OK', 'Настройки сохранены и проверены чтением. MCP: ' . ($expected['mcp_enabled'] === 'Y' ? 'включён' : 'выключен')
                    . ($siteops->oauthAvailable() ? '; подключение по ссылке: ' . ($expected['oauth_enabled'] === 'Y' ? 'включено' : 'выключено') : '')
                    . '; режим: ' . ($expected['mode'] === Settings::MODE_READWRITE ? 'чтение и запись' : 'только чтение') . '.'];
            }
        } elseif ($action === 'token') {
            $newToken = $settings->generateToken();
            $messages[] = ['OK', 'Новый токен создан. Прежний токен больше не работает.'];
        } elseif ($action === 'revoke_client' && $siteops->oauthAvailable()) {
            $clientId = (string) ($_POST['client_id'] ?? '');
            $siteops->oauthStore()->revokeClient($clientId);
            (new \OrgBox\SiteOps\Audit\BitrixAuditLogger())->log('oauth.revoke_client', ['client_id' => $clientId, 'user_id' => (int) $GLOBALS['USER']->GetID()]);
            $messages[] = ['OK', 'Подключение отозвано: токены этого клиента больше не работают.'];
        } elseif ($action === 'revoke') {
            $settings->revokeToken();
            $messages[] = ['OK', 'Токен отозван.'];
        } elseif ($action === 'check') {
            $release = $makeUpdater()->checkLatest();
            $messages[] = $release === null
                ? ['OK', 'Установлена последняя версия (' . $siteops->version() . ').']
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
    $settings = $siteops->settings();
}

$mcpUrl = $siteops->isStandalone()
    ? $siteops->baseUrl() . '/site.ops/mcp'
    : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/bitrix/tools/orgbox_siteops/mcp.php';
$connections = [];
if ($siteops->oauthAvailable() && $settings->oauthEnabled()) {
    try {
        $connections = $siteops->oauthStore()->listConnections(time());
    } catch (\Throwable $exception) {
        $messages[] = ['ERROR', 'Не удалось прочитать список подключений: ' . $exception->getMessage()];
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('orgBox: SiteOps — MCP-сервер');
foreach ($messages as [$type, $text]) {
    CAdminMessage::ShowMessage(['MESSAGE' => $text, 'TYPE' => $type]);
}
$action = $APPLICATION->GetCurPageParam('', [], false);
?>
<h3>Подключение</h3>
<table class="adm-detail-content-table edit-table">
    <tr><td width="35%">Версия модуля:</td><td><b><?=$h($siteops->version())?></b></td></tr>
    <tr><td>Хранилище настроек:</td><td><?php if ($siteops->isStandalone()): $fileStore = $siteops->optionStore(); ?>
        файл <code><?=$h($siteops->settingsFile())?></code>, запись <?=!($fileStore instanceof \OrgBox\SiteOps\Config\FileOptionStore) || $fileStore->isWritable() ? '<b style="color:#2a7a2a">возможна</b>' : '<b style="color:#b00">НЕВОЗМОЖНА: выдайте веб-серверу права на папку _data</b>'?>
    <?php else: ?>таблица b_option (общие для всей установки Битрикса)<?php endif; ?></td></tr>
    <tr><td>Адрес MCP-сервера:</td><td><input type="text" readonly size="80" value="<?=$h($mcpUrl)?>" onclick="this.select()"></td></tr>
    <tr><td>Токен доступа:</td><td>
        <?php if ($newToken !== ''): ?>
            <input type="text" readonly size="80" value="<?=$h($newToken)?>" onclick="this.select()"><br>
            <b>Скопируйте токен сейчас: он показывается один раз, на сайте хранится только его хэш.</b>
        <?php else: ?>
            <?=$settings->tokenHash() !== '' ? 'создан (значение не хранится)' : 'не создан'?>
        <?php endif; ?>
        <form method="post" action="<?=$h($action)?>" style="margin-top:8px">
            <?=$csrfFields()?>
            <button class="adm-btn" name="action" value="token" onclick="return confirm('Создать новый токен? Прежний перестанет работать.')">Создать новый токен</button>
            <button class="adm-btn" name="action" value="revoke" onclick="return confirm('Отозвать токен?')">Отозвать</button>
        </form>
    </td></tr>
</table>

<h3>Настройки</h3>
<form method="post" action="<?=$h($action)?>">
    <?=$csrfFields()?>
    <input type="hidden" name="action" value="save">
    <table class="adm-detail-content-table edit-table">
        <tr><td width="35%">MCP-сервер:</td><td><label><input type="checkbox" name="mcp_enabled" value="Y"<?=$settings->mcpEnabled() ? ' checked' : ''?>> включён</label></td></tr>
        <?php if ($siteops->oauthAvailable()): ?>
        <tr><td>Подключение по ссылке (OAuth):</td><td><label><input type="checkbox" name="oauth_enabled" value="Y"<?=$settings->oauthEnabled() ? ' checked' : ''?>> разрешить клиентам MCP подключаться через вход администратора</label><br><small>Клиент выдаст ссылку, вы войдёте в Битрикс и нажмёте «Разрешить». Токен вручную не нужен.</small></td></tr>
        <tr><td>Адрес сайта для OAuth:</td><td><input type="text" name="public_url" size="40" value="<?=$h($settings->publicUrl())?>" placeholder="<?=$h($siteops->baseUrl())?>"><br><small>https://домен без пути. Пусто — определяется по запросу. Лучше задать явно.</small></td></tr>
        <?php endif; ?>
        <tr><td>Режим:</td><td>
            <label><input type="radio" name="mode" value="readonly"<?=!$settings->writesEnabled() ? ' checked' : ''?>> только чтение (рекомендуется)</label><br>
            <label><input type="radio" name="mode" value="readwrite"<?=$settings->writesEnabled() ? ' checked' : ''?>> чтение и запись (удаления нет; изменения пишутся в журнал событий)</label>
        </td></tr>
        <tr><td>ID инфоблоков:</td><td><input type="text" name="allowed_iblocks" size="40" value="<?=$h(implode(', ', $settings->allowedIblocks()))?>"><br><small>Через запятую. Пусто — чтение всех инфоблоков. <b>Запись разрешена только для перечисленных.</b></small></td></tr>
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

<?php if ($siteops->oauthAvailable() && $settings->oauthEnabled()): ?>
<h3>Подключения (OAuth)</h3>
<p>Как подключить клиент: добавьте адрес <b><?=$h($mcpUrl)?></b> в Claude (в Claude Code: <code>claude mcp add --transport http siteops <?=$h($mcpUrl)?></code>, затем <code>/mcp</code> и «Authenticate»).
Клиент откроет ссылку на странице согласия: войдите администратором и нажмите «Разрешить».</p>
<?php if ($connections === []): ?>
    <p><i>Активных подключений нет.</i></p>
<?php else: ?>
    <table class="adm-list-table" style="max-width:900px">
        <tr class="adm-list-table-header"><td class="adm-list-table-cell">Клиент</td><td class="adm-list-table-cell">Адрес возврата</td><td class="adm-list-table-cell">Создан</td><td class="adm-list-table-cell">Последний токен</td><td class="adm-list-table-cell"></td></tr>
        <?php foreach ($connections as $connection): ?>
        <tr class="adm-list-table-row">
            <td class="adm-list-table-cell"><?=$h($connection['name'])?></td>
            <td class="adm-list-table-cell"><?=$h((string) (parse_url($connection['redirect_uris'][0] ?? '', PHP_URL_HOST) ?: ''))?></td>
            <td class="adm-list-table-cell"><?=$h(date('d.m.Y H:i', (int) $connection['created']))?></td>
            <td class="adm-list-table-cell"><?=$h(date('d.m.Y H:i', (int) $connection['last_used']))?></td>
            <td class="adm-list-table-cell">
                <form method="post" action="<?=$h($action)?>" style="margin:0">
                    <?=$csrfFields()?>
                    <input type="hidden" name="client_id" value="<?=$h($connection['client_id'])?>">
                    <button class="adm-btn" name="action" value="revoke_client" onclick="return confirm('Отозвать подключение?')">Отозвать</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php endif; ?>

<form method="post" action="<?=$h($action)?>" style="margin-top:16px">
    <?=$csrfFields()?>
    <button class="adm-btn" name="action" value="check">Проверить обновления</button>
    <?php if ($release !== null): ?>
        <button class="adm-btn adm-btn-green" name="action" value="update" onclick="return confirm('Установить версию <?=$h($release->version)?>? Текущая версия сохранится в резервной копии.')">Установить <?=$h($release->version)?></button>
        <?php if ($release->notes !== ''): ?><pre style="white-space:pre-wrap;max-width:900px"><?=$h($release->notes)?></pre><?php endif; ?>
    <?php endif; ?>
</form>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
