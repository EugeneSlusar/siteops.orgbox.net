<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

use OrgBox\SiteOps\Auth\AuthException;
use OrgBox\SiteOps\Auth\BearerTokenAuthenticator;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Mcp\McpServer;

/** HTTP-слой MCP: метод, размер, Content-Type, защита входа, токен, затем JSON-RPC. */
final class McpEndpoint
{
    private const MAX_BODY = 1048576;

    public function __construct(
        private Settings $settings,
        private RequestGuard $guard,
        private BearerTokenAuthenticator $auth,
        private McpServer $server,
    ) {
    }

    public function handle(string $method, array $server, string $body): HttpResponse
    {
        $common = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

        if (!$this->settings->mcpEnabled()) {
            return HttpResponse::json(404, ['error' => 'Не найдено.'], $common);
        }
        if (strtoupper($method) !== 'POST') {
            return HttpResponse::json(405, ['error' => 'Допускается только POST.'], $common + ['Allow' => 'POST']);
        }

        try {
            $this->guard->assertAllowed($server);
            $this->auth->assertValid($server);
        } catch (AuthException $exception) {
            $headers = $common;
            if ($exception->httpStatus() === 401) {
                $headers['WWW-Authenticate'] = 'Bearer realm="orgbox-siteops"';
            }

            return HttpResponse::json($exception->httpStatus(), ['error' => $exception->getMessage()], $headers);
        }

        if (stripos((string) ($server['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
            return HttpResponse::json(415, ['error' => 'Нужен Content-Type: application/json.'], $common);
        }
        if (strlen($body) > self::MAX_BODY) {
            return HttpResponse::json(413, ['error' => 'Запрос слишком большой.'], $common);
        }

        $result = $this->server->handle($body);
        if ($result['data'] === null) {
            return new HttpResponse($result['status'], null, $common);
        }

        return HttpResponse::json($result['status'], $result['data'], $common);
    }
}
