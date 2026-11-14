<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Mcp;

final class ToolRegistry
{
    /** @var array<string,Tool> */
    private array $tools = [];

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /** @return Tool[] инструменты, доступные в текущем режиме; запись скрыта в режиме «только чтение» */
    public function available(bool $writesEnabled): array
    {
        return array_values(array_filter($this->tools, static fn (Tool $tool): bool => $writesEnabled || !$tool->writes));
    }

    public function find(string $name, bool $writesEnabled): ?Tool
    {
        $tool = $this->tools[$name] ?? null;

        return $tool !== null && ($writesEnabled || !$tool->writes) ? $tool : null;
    }
}
