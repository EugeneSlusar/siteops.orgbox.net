<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

use OrgBox\SiteOps\Auth\AuthException;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\OAuth\OAuthException;
use OrgBox\SiteOps\OAuth\OAuthServer;

/**
 * HTTP-слой OAuth: документы обнаружения, регистрация клиента, выдача и отзыв токенов.
 * Страница согласия (authorize) — отдельный файл с проверкой администратора Битрикса.
 */
final class OAuthEndpoint
{
    public const ROUTES = ['as-metadata', 'oidc-configuration', 'jwks', 'resource-metadata', 'register', 'device', 'token', 'revoke'];

    public function __construct(
        private Settings $settings,
        private RequestGuard $guard,
        private OAuthServer $oauth,
    ) {
    }

    public function handle(string $route, string $method, array $server, string $rawBody): HttpResponse
    {
        $headers = [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            // Адреса публичные и работают без cookie: браузерным клиентам (например, MCP Inspector) нужен CORS.
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, MCP-Protocol-Version',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        ];
        $method = strtoupper($method);

        if (!$this->settings->mcpEnabled() || !$this->settings->oauthEnabled() || !in_array($route, self::ROUTES, true)) {
            return HttpResponse::json(404, ['error' => 'not_found'], $headers);
        }
        if ($method === 'OPTIONS') {
            return new HttpResponse(204, null, $headers);
        }

        $isMetadata = in_array($route, ['as-metadata', 'oidc-configuration', 'jwks', 'resource-metadata'], true);
        if ($isMetadata ? $method !== 'GET' : $method !== 'POST') {
            return HttpResponse::json(405, ['error' => 'method_not_allowed'], $headers + ['Allow' => $isMetadata ? 'GET, OPTIONS' : 'POST, OPTIONS']);
        }

        try {
            $this->guard->assertRateLimit($server, 'oauth');

            return match ($route) {
                'as-metadata' => HttpResponse::json(200, $this->oauth->authorizationServerMetadata(), ['Cache-Control' => 'public, max-age=300'] + $headers),
                'oidc-configuration' => HttpResponse::json(200, $this->oauth->openidConfiguration(), ['Cache-Control' => 'public, max-age=300'] + $headers),
                'jwks' => HttpResponse::json(200, $this->oauth->jwkSet(), ['Cache-Control' => 'public, max-age=300'] + $headers),
                'resource-metadata' => HttpResponse::json(200, $this->oauth->protectedResourceMetadata(), ['Cache-Control' => 'public, max-age=300'] + $headers),
                'register' => $this->register($server, $rawBody, $headers),
                'device' => HttpResponse::json(200, $this->oauth->startDevice($this->form($rawBody), (string) ($server['REMOTE_ADDR'] ?? '')), $headers),
                'token' => HttpResponse::json(200, $this->oauth->token($this->form($rawBody)), $headers),
                'revoke' => $this->revoke($rawBody, $headers),
            };
        } catch (OAuthException $exception) {
            return HttpResponse::json($exception->httpStatus, ['error' => $exception->error, 'error_description' => $exception->getMessage()], $headers);
        } catch (AuthException $exception) {
            return HttpResponse::json($exception->httpStatus(), ['error' => 'rate_limited', 'error_description' => $exception->getMessage()], $headers);
        }
    }

    /** @param array<string,string> $headers */
    private function register(array $server, string $rawBody, array $headers): HttpResponse
    {
        if (stripos((string) ($server['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
            throw new OAuthException('invalid_client_metadata', 'Нужен Content-Type: application/json.');
        }
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new OAuthException('invalid_client_metadata', 'Тело запроса должно быть JSON-объектом.');
        }

        return HttpResponse::json(201, $this->oauth->register($payload), $headers);
    }

    /** @param array<string,string> $headers */
    private function revoke(string $rawBody, array $headers): HttpResponse
    {
        $this->oauth->revoke(OAuthServer::str($this->form($rawBody), 'token'));

        return new HttpResponse(200, '{}', ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    /** @return array<string,mixed> */
    private function form(string $rawBody): array
    {
        $form = [];
        parse_str($rawBody, $form);

        return $form;
    }
}
