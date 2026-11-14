<?php

declare(strict_types=1);

// MCP-сервер: https://ваш-сайт/site.ops/mcp.php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require __DIR__ . '/autoload.php';

try {
    $body = (string) file_get_contents('php://input');
    $response = \OrgBox\SiteOps\Bootstrap::standalone()->mcpEndpoint()->handle((string) $_SERVER['REQUEST_METHOD'], $_SERVER, $body);
} catch (\Throwable $exception) {
    \OrgBox\SiteOps\Audit\BitrixAuditLogger::logError($exception);
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo '{"error":"Внутренняя ошибка."}';
    exit;
}

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
if ($response->body !== null) {
    echo $response->body;
}
exit;
