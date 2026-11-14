<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Http;

final class HttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly ?string $body = null,
        public readonly array $headers = [],
    ) {
    }

    public static function json(int $status, mixed $data, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ['Content-Type' => 'application/json; charset=UTF-8'] + $headers,
        );
    }
}
