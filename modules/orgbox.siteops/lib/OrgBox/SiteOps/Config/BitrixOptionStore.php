<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Config;

use Bitrix\Main\Config\Option;

final class BitrixOptionStore implements OptionStore
{
    public const MODULE_ID = 'orgbox.siteops';

    public function get(string $name, string $default = ''): string
    {
        return (string) Option::get(self::MODULE_ID, $name, $default);
    }

    public function set(string $name, string $value): void
    {
        Option::set(self::MODULE_ID, $name, $value);
    }
}
