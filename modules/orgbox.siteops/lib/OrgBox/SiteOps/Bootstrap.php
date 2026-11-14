<?php

declare(strict_types=1);

namespace OrgBox\SiteOps;

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
use OrgBox\SiteOps\Update\PackageProfile;

/**
 * Сборка объектов внутри Битрикса. Две формы установки с одним и тем же кодом:
 *  - модуль:       /local/modules/orgbox.siteops   (настройки общие для установки Битрикса);
 *  - папка сайта:  <корень сайта>/site.ops          (настройки отдельные для каждого сайта по SITE_ID).
 */
final class Bootstrap
{
    private function __construct(
        private PackageProfile $profile,
        private string $baseDir,
        private string $siteId,
    ) {
    }

    /** Формальный модуль: папка модуля = на уровень выше lib/. */
    public static function module(): self
    {
        return new self(PackageProfile::module(), dirname(__DIR__, 3), '');
    }

    /** Папка site.ops в корне сайта; настройки привязаны к текущему сайту. */
    public static function standalone(): self
    {
        $siteId = defined('SITE_ID') ? (string) SITE_ID : '';

        return new self(PackageProfile::standalone(), dirname(__DIR__, 3), $siteId);
    }

    public function profile(): PackageProfile
    {
        return $this->profile;
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    public function isStandalone(): bool
    {
        return $this->profile->root === 'site.ops';
    }

    public function version(): string
    {
        return ModuleInfo::version($this->baseDir, $this->profile->versionFile);
    }

    public function settings(): Settings
    {
        return new Settings(new BitrixOptionStore($this->siteId));
    }

    /** Папка для резервных копий и временных файлов обновления (закрыта от веба). */
    public function workDir(): string
    {
        return $this->isStandalone()
            ? $this->baseDir . '/_updates'
            : $_SERVER['DOCUMENT_ROOT'] . '/upload/orgbox_siteops/updates';
    }

    public function tools(Settings $settings): ToolRegistry
    {
        $registry = new ToolRegistry();
        (new IblockToolset(new BitrixIblockGateway(), $settings, new BitrixAuditLogger()))->registerAll($registry);

        return $registry;
    }

    public function mcpEndpoint(): McpEndpoint
    {
        $settings = $this->settings();
        $server = new McpServer(
            $this->tools($settings),
            $settings->writesEnabled(),
            $this->version(),
            [BitrixAuditLogger::class, 'logError'],
        );

        return new McpEndpoint(
            $settings,
            new RequestGuard($settings, new BitrixCounterStore()),
            new BearerTokenAuthenticator($settings),
            $server,
        );
    }

    public function apiEndpoint(): ApiEndpoint
    {
        $settings = $this->settings();
        $uri = $this->isStandalone() ? '/site.ops/api.php' : '/bitrix/tools/orgbox_siteops/api.php';

        return new ApiEndpoint(
            $settings,
            new RequestGuard($settings, new BitrixCounterStore()),
            new HmacAuthenticator($settings, new BitrixNonceStore()),
            $this->tools($settings),
            $uri,
        );
    }
}
