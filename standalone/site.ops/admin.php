<?php

declare(strict_types=1);

// Страница настроек: https://ваш-сайт/site.ops/admin.php (только администратор Битрикса)
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require __DIR__ . '/autoload.php';

global $APPLICATION, $USER;
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ разрешён только администратору.');
}

$siteops = \OrgBox\SiteOps\Bootstrap::standalone();
require __DIR__ . '/lib/OrgBox/SiteOps/Admin/settings.view.php';
