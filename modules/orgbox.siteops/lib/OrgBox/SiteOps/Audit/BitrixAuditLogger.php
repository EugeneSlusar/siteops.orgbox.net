<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Audit;

/** Пишет в штатный «Журнал событий» Битрикса (Настройки → Инструменты → Журнал событий). */
final class BitrixAuditLogger implements AuditLogger
{
    public function log(string $action, array $context): void
    {
        $description = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        \CEventLog::Add([
            'SEVERITY' => 'INFO',
            'AUDIT_TYPE_ID' => 'ORGBOX_SITEOPS',
            'MODULE_ID' => 'orgbox.siteops',
            'ITEM_ID' => $action,
            'DESCRIPTION' => mb_substr($description, 0, 60000),
        ]);
    }

    public static function logError(\Throwable $exception): void
    {
        \CEventLog::Add([
            'SEVERITY' => 'ERROR',
            'AUDIT_TYPE_ID' => 'ORGBOX_SITEOPS_ERROR',
            'MODULE_ID' => 'orgbox.siteops',
            'ITEM_ID' => get_class($exception),
            'DESCRIPTION' => mb_substr($exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine(), 0, 4000),
        ]);
    }
}
