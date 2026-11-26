<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Tools;

use OrgBox\SiteOps\Audit\AuditLogger;
use OrgBox\SiteOps\Mcp\Tool;
use OrgBox\SiteOps\Mcp\ToolException;
use OrgBox\SiteOps\Mcp\ToolRegistry;

/** Безопасные операции только с обычными текстовыми файлами внутри DOCUMENT_ROOT. */
final class FileToolset
{
    private const MAX_LIMIT = 2000;
    private const MAX_BYTES = 5_000_000;
    private const EXTENSIONS = ['php', 'html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'md', 'csv', 'yml', 'yaml'];
    private const BLOCKED_DIRECTORIES = ['bitrix', 'upload', 'backup', 'backups', 'log', 'logs', 'cache', 'caches', 'session', 'sessions', 'tmp', 'temp'];
    private const BLOCKED_NAMES = ['password', 'secret', 'token', 'credential', 'config', 'dbconn'];

    public function __construct(private string $publicRoot, private AuditLogger $audit)
    {
    }

    public function registerAll(ToolRegistry $registry): void
    {
        $path = ['type' => 'string', 'minLength' => 1, 'description' => 'Относительный путь от публичного корня сайта.'];
        $registry->register(new Tool('file_read', 'Прочитать обычный текстовый файл из публичного корня сайта. Скрытые и служебные пути недоступны.', [
            'type' => 'object',
            'properties' => [
                'path' => $path,
                'offset' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Номер первой строки; по умолчанию 1.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Число строк; по умолчанию 200, максимум 2000.'],
            ],
            'required' => ['path'],
        ], false, fn (array $args): array => $this->read($args)));
        $registry->register(new Tool('file_rename', 'Безопасно переименовать один обычный текстовый файл внутри публичного корня. Целевой файл не должен существовать.', [
            'type' => 'object',
            'properties' => ['from' => $path, 'to' => $path],
            'required' => ['from', 'to'],
        ], true, fn (array $args): array => $this->rename($args)));
    }

    /** @return array{path:string,content:string,truncated:bool} */
    private function read(array $args): array
    {
        $id = $this->callId();
        $path = is_string($args['path'] ?? null) ? $args['path'] : '';
        try {
            $relative = $this->normalize($path);
            $file = $this->existingFile($relative);
            $offset = $this->positiveInt($args, 'offset', 1, PHP_INT_MAX);
            $limit = $this->positiveInt($args, 'limit', 200, self::MAX_LIMIT);
            $contents = @file_get_contents($file);
            if (!is_string($contents) || strlen($contents) > self::MAX_BYTES || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
                throw new ToolException('Файл не является поддерживаемым текстовым файлом.');
            }
            $lines = preg_split("/\r\n|\n|\r/", $contents);
            if ($lines === false) {
                throw new ToolException('Не удалось прочитать текстовый файл.');
            }
            $slice = array_slice($lines, $offset - 1, $limit);
            $numbered = [];
            foreach ($slice as $index => $line) {
                $numbered[] = ($offset + $index) . ': ' . $line;
            }
            $result = ['path' => $relative, 'content' => implode("\n", $numbered), 'truncated' => count($lines) > $offset - 1 + $limit];
            $this->audit->log('file.read', ['call_id' => $id, 'path' => $relative, 'result' => 'success']);

            return $result;
        } catch (ToolException $exception) {
            $this->audit->log('file.read', ['call_id' => $id, 'path' => $this->auditPath($path), 'result' => 'denied']);
            throw $exception;
        }
    }

    /** @return array{from:string,to:string,size:int,verified:bool} */
    private function rename(array $args): array
    {
        $id = $this->callId();
        $from = is_string($args['from'] ?? null) ? $args['from'] : '';
        $to = is_string($args['to'] ?? null) ? $args['to'] : '';
        try {
            $sourcePath = $this->normalize($from);
            $targetPath = $this->normalize($to);
            $source = $this->existingFile($sourcePath);
            $target = $this->newFilePath($targetPath);
            $size = filesize($source);
            if (!is_int($size) || !@rename($source, $target)) {
                throw new ToolException('Не удалось переименовать файл.');
            }
            clearstatcache(true, $target);
            $verified = !file_exists($source) && is_file($target) && !is_link($target) && filesize($target) === $size;
            if (!$verified) {
                throw new ToolException('Проверка переименования файла не пройдена.');
            }
            $result = ['from' => $sourcePath, 'to' => $targetPath, 'size' => $size, 'verified' => true];
            $this->audit->log('file.rename', ['call_id' => $id, 'from' => $sourcePath, 'to' => $targetPath, 'size' => $size, 'result' => 'success']);

            return $result;
        } catch (ToolException $exception) {
            $this->audit->log('file.rename', ['call_id' => $id, 'from' => $this->auditPath($from), 'to' => $this->auditPath($to), 'result' => 'denied']);
            throw $exception;
        }
    }

    private function normalize(string $path): string
    {
        if ($path === '' || strlen($path) > 1000 || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '\\')) {
            throw new ToolException('Нужен допустимый относительный путь внутри публичного корня.');
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            $lower = strtolower($part);
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.') || in_array($lower, self::BLOCKED_DIRECTORIES, true)) {
                throw new ToolException('Этот путь недоступен.');
            }
        }
        $name = strtolower(end($parts) ?: '');
        foreach (self::BLOCKED_NAMES as $blocked) {
            if (str_contains($name, $blocked)) {
                throw new ToolException('Этот файл недоступен.');
            }
        }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if ($extension === 'bak') {
            $extension = pathinfo(pathinfo($name, PATHINFO_FILENAME), PATHINFO_EXTENSION);
        }
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new ToolException('Разрешены только текстовые файлы контента и шаблонов.');
        }

        return implode('/', $parts);
    }

    private function existingFile(string $relative): string
    {
        $candidate = $this->safePath($relative);
        if (!file_exists($candidate) || is_link($candidate) || !is_file($candidate)) {
            throw new ToolException('Файл не существует или не является обычным файлом.');
        }
        $resolved = realpath($candidate);
        if ($resolved === false || !$this->insideRoot($resolved)) {
            throw new ToolException('Этот путь недоступен.');
        }

        return $resolved;
    }

    private function newFilePath(string $relative): string
    {
        $candidate = $this->safePath($relative);
        if (file_exists($candidate) || is_link($candidate)) {
            throw new ToolException('Целевой файл уже существует.');
        }

        return $candidate;
    }

    private function safePath(string $relative): string
    {
        $root = realpath($this->publicRoot);
        if ($root === false || !is_dir($root)) {
            throw new ToolException('Публичный корень сайта недоступен.');
        }
        $current = $root;
        $parts = explode('/', $relative);
        foreach (array_slice($parts, 0, -1) as $part) {
            $current .= '/' . $part;
            if (!is_dir($current) || is_link($current)) {
                throw new ToolException('Каталог пути недоступен.');
            }
        }
        if (!$this->insideRoot($current)) {
            throw new ToolException('Этот путь недоступен.');
        }

        return $root . '/' . $relative;
    }

    private function insideRoot(string $path): bool
    {
        $root = realpath($this->publicRoot);

        return $root !== false && ($path === $root || str_starts_with($path, $root . '/'));
    }

    private function positiveInt(array $args, string $key, int $default, int $max): int
    {
        $value = $args[$key] ?? $default;
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new ToolException($key . ' должен быть целым числом от 1 до ' . $max . '.');
        }

        return $value;
    }

    private function callId(): string
    {
        return bin2hex(random_bytes(12));
    }

    private function auditPath(string $path): string
    {
        return mb_substr(str_replace("\0", '', $path), 0, 1000);
    }
}
