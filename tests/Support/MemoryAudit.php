<?php

declare(strict_types=1);

namespace Tests\Support;

use OrgBox\SiteOps\Audit\AuditLogger;

final class MemoryAudit implements AuditLogger
{
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $entries = [];

    public function log(string $action, array $context): void
    {
        $this->entries[] = [$action, $context];
    }
}
