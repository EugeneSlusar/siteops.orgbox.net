<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

/** Описание формы установки: корневая папка архива, обязательные файлы, где лежит версия. */
final class PackageProfile
{
    /** @param list<string> $required */
    private function __construct(
        public readonly string $root,
        public readonly array $required,
        public readonly string $versionFile,
    ) {
    }

    /** Формальный модуль Битрикса в /local/modules или /bitrix/modules. */
    public static function module(): self
    {
        return new self('orgbox.siteops', ['include.php', 'install/index.php', 'install/version.php', 'lib'], 'install/version.php');
    }

    /** Отдельная папка в публичной части сайта: public_html/site.ops. */
    public static function standalone(): self
    {
        return new self('site.ops', ['mcp.php', 'api.php', 'admin.php', 'autoload.php', 'version.php', '.htaccess', 'lib'], 'version.php');
    }

    /** Имя архива релиза: <корень>-<версия>.zip */
    public function zipName(string $version): string
    {
        return $this->root . '-' . $version . '.zip';
    }
}
