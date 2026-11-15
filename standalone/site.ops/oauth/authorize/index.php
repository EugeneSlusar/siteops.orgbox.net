<?php

declare(strict_types=1);

// Страница согласия OAuth: сюда клиент MCP отправляет администратора по ссылке подключения.
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require __DIR__ . '/../../autoload.php';

global $APPLICATION, $USER;
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Войдите администратором, чтобы разрешить подключение ИИ-ассистента.');
}

$siteops = \OrgBox\SiteOps\Bootstrap::standalone();
require __DIR__ . '/../../lib/OrgBox/SiteOps/Admin/authorize.view.php';
