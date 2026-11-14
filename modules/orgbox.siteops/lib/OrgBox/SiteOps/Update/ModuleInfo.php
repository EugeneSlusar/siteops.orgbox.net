<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

final class ModuleInfo
{
    public static function version(string $moduleDir, string $versionFile = 'install/version.php'): string
    {
        $file = rtrim($moduleDir, '/') . '/' . $versionFile;
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
