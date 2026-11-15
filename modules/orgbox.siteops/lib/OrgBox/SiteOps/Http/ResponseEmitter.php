<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

/** Отправляет HttpResponse клиенту и завершает скрипт. */
final class ResponseEmitter
{
    public static function send(HttpResponse $response): never
    {
        http_response_code($response->status);
        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($response->body !== null) {
            echo $response->body;
        }
        exit;
    }
}
