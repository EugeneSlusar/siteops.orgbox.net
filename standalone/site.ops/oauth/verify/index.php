<?php

declare(strict_types=1);

// Подтверждение кода устройства (Device Flow): сюда человек попадает по ссылке из клиента MCP.
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require __DIR__ . '/../../autoload.php';

global $APPLICATION, $USER;
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Войдите администратором, чтобы подтвердить подключение ИИ-ассистента.');
}

$siteops = \OrgBox\SiteOps\Bootstrap::standalone();
require __DIR__ . '/../../lib/OrgBox/SiteOps/Admin/verify.view.php';
