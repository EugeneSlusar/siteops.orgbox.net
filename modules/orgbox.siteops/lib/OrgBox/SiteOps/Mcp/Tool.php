<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Mcp;

final class Tool
{
    /**
     * @param array<string,mixed> $inputSchema JSON Schema аргументов
     * @param callable(array):mixed $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly bool $writes,
        private $handler,
    ) {
    }

    public function call(array $arguments): mixed
    {
        return ($this->handler)($arguments);
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'annotations' => [
                'readOnlyHint' => !$this->writes,
                'destructiveHint' => false,
                'idempotentHint' => !$this->writes,
                'openWorldHint' => false,
            ],
        ];
    }
}
