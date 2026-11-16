<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;

class orgbox_siteops extends CModule
{
    public $MODULE_ID = 'orgbox.siteops';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'orgBox: SiteOps';
    public $MODULE_DESCRIPTION = 'MCP-сервер и защищённый API для управления инфоблоками сайта из ИИ-ассистентов.';
    public $PARTNER_NAME = 'orgBox';
    public $PARTNER_URI = 'https://orgbox.net';

    public function __construct()
    {
        $arModuleVersion = [];
        require __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall(): void
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallFiles();
        // По умолчанию всё выключено: сначала администратор включает MCP и создаёт токен.
        foreach (['mcp_enabled' => 'N', 'mode' => 'readonly', 'allowed_iblocks' => ''] as $name => $value) {
            if (Option::get($this->MODULE_ID, $name, null) === null) {
                Option::set($this->MODULE_ID, $name, $value);
            }
        }
    }

    public function DoUninstall(): void
    {
        $this->UnInstallFiles();
        Option::delete($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    /** Копирует точки входа и страницы админки. Вызывается и при обновлении модуля. */
    public function InstallFiles(): bool
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        CopyDirFiles(__DIR__ . '/admin', $root . '/bitrix/admin', true, true);
        CopyDirFiles(__DIR__ . '/tools', $root . '/bitrix/tools', true, true);

        return true;
    }

    public function UnInstallFiles(): bool
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        DeleteDirFiles(__DIR__ . '/admin', $root . '/bitrix/admin');
        DeleteDirFiles(__DIR__ . '/tools', $root . '/bitrix/tools');

        return true;
    }
}
