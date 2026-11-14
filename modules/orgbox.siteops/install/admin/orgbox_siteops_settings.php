<?php

// Прокси страницы настроек: модуль может лежать в /local/modules или /bitrix/modules.
$root = $_SERVER['DOCUMENT_ROOT'];
$page = $root . '/local/modules/orgbox.siteops/admin/settings.php';
if (!is_file($page)) {
    $page = $root . '/bitrix/modules/orgbox.siteops/admin/settings.php';
}
require $page;
