<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @throws UpdateException
     */
    public function get(string $url, array $headers = [], int $maxBytes = 26214400): string;
}
