<?php

declare(strict_types=1);

namespace OrgBox\SiteOps;

use OrgBox\SiteOps\Audit\BitrixAuditLogger;
use OrgBox\SiteOps\Auth\BearerTokenAuthenticator;
use OrgBox\SiteOps\Config\BitrixOptionStore;
use OrgBox\SiteOps\Config\FileOptionStore;
use OrgBox\SiteOps\Config\OptionStore;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Http\BitrixCounterStore;
use OrgBox\SiteOps\Http\McpEndpoint;
use OrgBox\SiteOps\Http\OAuthEndpoint;
use OrgBox\SiteOps\Http\RequestGuard;
use OrgBox\SiteOps\Iblock\BitrixIblockGateway;
use OrgBox\SiteOps\Mcp\McpServer;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\OAuth\BitrixOAuthStore;
use OrgBox\SiteOps\OAuth\OAuthServer;
use OrgBox\SiteOps\OAuth\OAuthStore;
use OrgBox\SiteOps\Tools\IblockToolset;
use OrgBox\SiteOps\Update\ModuleInfo;
use OrgBox\SiteOps\Update\PackageProfile;

/**
 * Сборка объектов внутри Битрикса. Две формы установки с одним и тем же кодом:
 *  - модуль:       /local/modules/orgbox.siteops   (настройки в таблице b_option, общие для установки Битрикса);
 *  - папка сайта:  <корень сайта>/site.ops          (настройки в файле _data/settings.php внутри папки: у каждого сайта свои).
 */
final class Bootstrap
{
    private function __construct(
        private PackageProfile $profile,
        private string $baseDir,
        /** Подмена хранилища настроек (для тестов). */
        private ?OptionStore $storeOverride = null,
    ) {
    }

    /** Формальный модуль: папка модуля = на уровень выше lib/. */
    public static function module(): self
    {
        return new self(PackageProfile::module(), dirname(__DIR__, 3));
    }

    /** Папка site.ops в корне сайта; настройки лежат в ней же. */
    public static function standalone(?string $baseDir = null, ?OptionStore $store = null): self
    {
        return new self(PackageProfile::standalone(), $baseDir ?? dirname(__DIR__, 3), $store);
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

    /** Файл настроек формы «папка site.ops». */
    public function settingsFile(): string
    {
        return $this->baseDir . '/_data/settings.php';
    }

    public function optionStore(): OptionStore
    {
        // Формальный модуль: b_option без привязки к сайту. Папка site.ops: файл внутри папки.
        return $this->storeOverride
            ?? ($this->isStandalone() ? new FileOptionStore($this->settingsFile()) : new BitrixOptionStore(''));
    }

    public function settings(): Settings
    {
        return new Settings($this->optionStore());
    }

    /**
     * Ключ сайта для таблиц OAuth: 8 символов от пути корня сайта. Одинаков в админке и на публичных страницах
     * (в отличие от SITE_ID Битрикса) и различается у разных сайтов, даже если у них общая база.
     */
    public function siteKey(): string
    {
        $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? $this->baseDir);

        return substr(md5(realpath($root) ?: $root), 0, 8);
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

    /** Адрес сайта для OAuth-документов: из настроек, иначе из запроса (только допустимые символы хоста). */
    public function baseUrl(): string
    {
        $configured = $this->settings()->publicUrl();
        if ($configured !== '') {
            return $configured;
        }
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/', $host) ? $host : 'localhost';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return ($https ? 'https://' : 'http://') . $host;
    }

    /** OAuth доступен только в форме «папка site.ops» (адреса и таблицы привязаны к сайту). */
    public function oauthAvailable(): bool
    {
        return $this->isStandalone();
    }

    public function oauthStore(): OAuthStore
    {
        return new BitrixOAuthStore($this->siteKey());
    }

    public function oauthServer(): OAuthServer
    {
        return new OAuthServer($this->oauthStore(), $this->baseUrl());
    }

    public function oauthEndpoint(): OAuthEndpoint
    {
        $settings = $this->settings();

        return new OAuthEndpoint($settings, new RequestGuard($settings, new BitrixCounterStore()), $this->oauthServer());
    }

    public function mcpEndpoint(): McpEndpoint
    {
        $settings = $this->settings();
        // Таблицы OAuth создаются только если OAuth включён.
        $oauth = $this->oauthAvailable() && $settings->oauthEnabled() ? $this->oauthServer() : null;
        $server = new McpServer(
            $this->tools($settings),
            $settings->writesEnabled(),
            $this->version(),
            [BitrixAuditLogger::class, 'logError'],
        );

        return new McpEndpoint(
            $settings,
            new RequestGuard($settings, new BitrixCounterStore()),
            new BearerTokenAuthenticator($settings, $oauth),
            $server,
            $oauth?->resourceMetadataUrl(),
        );
    }
}
