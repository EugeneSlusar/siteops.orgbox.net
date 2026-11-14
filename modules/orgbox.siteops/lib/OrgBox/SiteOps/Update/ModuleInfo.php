<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

final class ModuleInfo
{
    public static function version(string $moduleDir): string
    {
        $file = rtrim($moduleDir, '/') . '/install/version.php';
        if (!is_file($file)) {
            return '0.0.0';
        }
        $arModuleVersion = [];
        (static function () use ($file, &$arModuleVersion): void {
            require $file;
        })();

        return (string) ($arModuleVersion['VERSION'] ?? '0.0.0');
    }
}
