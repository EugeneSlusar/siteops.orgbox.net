<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

final class ReleaseInfo
{
    public function __construct(
        public readonly string $version,
        public readonly string $zipUrl,
        public readonly string $sha256Url,
        public readonly ?string $signatureUrl,
        public readonly string $notes = '',
        /** true — ссылки ведут на API GitHub (приватный репозиторий, нужен Accept: application/octet-stream) */
        public readonly bool $viaApi = false,
    ) {
    }
}
