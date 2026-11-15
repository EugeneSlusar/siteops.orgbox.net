<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

use OrgBox\SiteOps\Audit\BitrixAuditLogger;
use OrgBox\SiteOps\Bootstrap;

/** Общий код точек входа OAuth (site.ops/oauth/<адрес>/index.php): читает запрос, вызывает OAuthEndpoint, отвечает. */
final class OAuthRoute
{
    public static function run(string $route): never
    {
        try {
            $response = Bootstrap::standalone()->oauthEndpoint()->handle(
                $route,
                (string) $_SERVER['REQUEST_METHOD'],
                $_SERVER,
                (string) file_get_contents('php://input'),
            );
        } catch (\Throwable $exception) {
            BitrixAuditLogger::logError($exception);
            $response = HttpResponse::json(500, ['error' => 'server_error'], ['Cache-Control' => 'no-store']);
        }
        ResponseEmitter::send($response);
    }
}
