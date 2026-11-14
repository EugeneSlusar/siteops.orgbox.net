<?php

declare(strict_types=1);

namespace Tests\Support;

use OrgBox\SiteOps\Config\OptionStore;

final class ArrayOptionStore implements OptionStore
{
    /** @param array<string,string> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->values[$name] ?? $default;
    }

    public function set(string $name, string $value): void
    {
        $this->values[$name] = $value;
    }
}
