<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Config;

/** Хранилище настроек модуля. В Битриксе это b_option, в тестах — массив. */
interface OptionStore
{
    public function get(string $name, string $default = ''): string;

    public function set(string $name, string $value): void;
}
