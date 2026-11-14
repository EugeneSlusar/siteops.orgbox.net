<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use OrgBox\SiteOps\Bootstrap;

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

$siteops = Bootstrap::module();
require __DIR__ . '/../lib/OrgBox/SiteOps/Admin/settings.view.php';
