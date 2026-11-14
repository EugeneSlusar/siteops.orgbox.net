<?php

declare(strict_types=1);

// Старый подписанный JSON API (HMAC): https://ваш-сайт/bitrix/tools/orgbox_siteops/api.php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

try {
    if (!Loader::includeModule('orgbox.siteops')) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo '{"ok":false,"error":"Не найдено."}';
        exit;
    }
    $body = (string) file_get_contents('php://input');
    $response = \OrgBox\SiteOps\Bootstrap::apiEndpoint()->handle((string) $_SERVER['REQUEST_METHOD'], $_SERVER, $body);
} catch (\Throwable $exception) {
    \OrgBox\SiteOps\Audit\BitrixAuditLogger::logError($exception);
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo '{"ok":false,"error":"Внутренняя ошибка."}';
    exit;
}

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo (string) $response->body;
exit;
