<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Files;

interface ImageDownloader
{
    /** @throws \OrgBox\SiteOps\Mcp\ToolException */
    public function download(string $url, int $maxBytes): string;
}
