<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Config;

use Bitrix\Main\Config\Option;

/**
 * Настройки в таблице b_option. $siteId = '' — общие для всей установки Битрикса (режим модуля),
 * $siteId = 's2' — отдельные для сайта (режим папки site.ops): на сайтах одного ядра токен и права не смешиваются.
 */
final class BitrixOptionStore implements OptionStore
{
    public const MODULE_ID = 'orgbox.siteops';

    public function __construct(private string $siteId = '')
    {
    }

    public function get(string $name, string $default = ''): string
    {
        return (string) Option::get(self::MODULE_ID, $name, $default, $this->siteId);
    }

    public function set(string $name, string $value): void
    {
        Option::set(self::MODULE_ID, $name, $value, $this->siteId);
    }
}
