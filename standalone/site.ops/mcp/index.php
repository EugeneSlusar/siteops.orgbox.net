<?php

declare(strict_types=1);

// MCP-сервер: https://ваш-сайт/site.ops/mcp
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require __DIR__ . '/../autoload.php';

use OrgBox\SiteOps\Audit\BitrixAuditLogger;
use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Http\HttpResponse;
use OrgBox\SiteOps\Http\ResponseEmitter;

try {
    $response = Bootstrap::standalone()->mcpEndpoint()->handle(
        (string) $_SERVER['REQUEST_METHOD'],
        $_SERVER,
        (string) file_get_contents('php://input'),
    );
} catch (\Throwable $exception) {
    BitrixAuditLogger::logError($exception);
    $response = HttpResponse::json(500, ['error' => 'Внутренняя ошибка.']);
}
ResponseEmitter::send($response);
