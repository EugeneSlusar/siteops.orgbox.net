<?php

declare(strict_types=1);

namespace OrgBox\SiteOps;

use Bitrix\Main\Loader;
use OrgBox\SiteOps\Audit\BitrixAuditLogger;
use OrgBox\SiteOps\Auth\BearerTokenAuthenticator;
use OrgBox\SiteOps\Auth\BitrixNonceStore;
use OrgBox\SiteOps\Auth\HmacAuthenticator;
use OrgBox\SiteOps\Config\BitrixOptionStore;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Http\ApiEndpoint;
use OrgBox\SiteOps\Http\BitrixCounterStore;
use OrgBox\SiteOps\Http\McpEndpoint;
use OrgBox\SiteOps\Http\RequestGuard;
use OrgBox\SiteOps\Iblock\BitrixIblockGateway;
use OrgBox\SiteOps\Mcp\McpServer;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\Tools\IblockToolset;
use OrgBox\SiteOps\Update\ModuleInfo;

/** Сборка объектов модуля для работы внутри Битрикса. */
final class Bootstrap
{
    public static function moduleDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function version(): string
    {
        return ModuleInfo::version(self::moduleDir());
    }

    public static function settings(): Settings
    {
        return new Settings(new BitrixOptionStore());
    }

    public static function tools(Settings $settings): ToolRegistry
    {
        $registry = new ToolRegistry();
        (new IblockToolset(new BitrixIblockGateway(), $settings, new BitrixAuditLogger()))->registerAll($registry);

        return $registry;
    }

    public static function mcpEndpoint(): McpEndpoint
    {
        Loader::includeModule('orgbox.siteops');
        $settings = self::settings();
        $server = new McpServer(
            self::tools($settings),
            $settings->writesEnabled(),
            self::version(),
            [BitrixAuditLogger::class, 'logError'],
        );

        return new McpEndpoint(
            $settings,
            new RequestGuard($settings, new BitrixCounterStore()),
            new BearerTokenAuthenticator($settings),
            $server,
        );
    }

    public static function apiEndpoint(): ApiEndpoint
    {
        Loader::includeModule('orgbox.siteops');
        $settings = self::settings();

        return new ApiEndpoint(
            $settings,
            new RequestGuard($settings, new BitrixCounterStore()),
            new HmacAuthenticator($settings, new BitrixNonceStore()),
            self::tools($settings),
        );
    }
}
