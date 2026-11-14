<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Audit;

interface AuditLogger
{
    /** @param array<string,mixed> $context */
    public function log(string $action, array $context): void;
}
