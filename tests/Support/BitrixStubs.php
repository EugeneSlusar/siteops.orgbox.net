<?php

declare(strict_types=1);

namespace Bitrix\Main\Config;

/** Заглушка Option: запоминает значения по ключам «модуль / сайт / имя». Используется только в тестах. */
final class Option
{
    /** @var array<string,string> */
    public static array $store = [];

    public static function get(string $module, string $name, string $default = '', string|false $siteId = false): string
    {
        $key = $module . '|' . ($siteId === false ? '' : $siteId) . '|' . $name;

        return self::$store[$key] ?? $default;
    }

    public static function set(string $module, string $name, string $value = '', string $siteId = ''): void
    {
        self::$store[$module . '|' . $siteId . '|' . $name] = $value;
    }
}
