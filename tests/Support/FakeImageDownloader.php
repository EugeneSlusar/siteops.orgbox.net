<?php

declare(strict_types=1);

namespace Tests\Support;

use OrgBox\SiteOps\Files\ImageDownloader;
use OrgBox\SiteOps\Mcp\ToolException;

final class FakeImageDownloader implements ImageDownloader
{
    /** @var list<string> */
    public array $requests = [];

    /** @param array<string,string> $responses */
    public function __construct(public array $responses = [])
    {
    }

    public function download(string $url, int $maxBytes): string
    {
        $this->requests[] = $url;

        return $this->responses[$url] ?? throw new ToolException('404 ' . $url);
    }
}
