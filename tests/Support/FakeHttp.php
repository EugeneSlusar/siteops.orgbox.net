<?php

declare(strict_types=1);

namespace Tests\Support;

use OrgBox\SiteOps\Update\HttpClient;
use OrgBox\SiteOps\Update\UpdateException;

final class FakeHttp implements HttpClient
{
    /** @var list<array{url:string,headers:array<string,string>}> */
    public array $requests = [];

    /** @param array<string,string> $responses */
    public function __construct(public array $responses)
    {
    }

    public function get(string $url, array $headers = [], int $maxBytes = 26214400): string
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        if (!isset($this->responses[$url])) {
            throw new UpdateException('404 ' . $url);
        }

        return $this->responses[$url];
    }
}
