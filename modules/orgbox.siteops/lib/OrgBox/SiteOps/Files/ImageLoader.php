<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Files;

use OrgBox\SiteOps\Mcp\ToolException;

/**
 * Превращает описание файла из аргументов инструмента в проверенный FileUpload.
 * Принимает {"url": "https://…"} или {"base64": "…", "name": "photo.jpg"}.
 * Тип определяется по содержимому, а не по расширению; допускаются JPEG, PNG, WebP и GIF,
 * а для файловых свойств (allowDocuments) ещё и PDF.
 */
final class ImageLoader
{
    public const MAX_BYTES = 10485760;

    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(private ?ImageDownloader $downloader = null)
    {
    }

    /** @param array<string,mixed> $spec */
    public function load(string $field, array $spec, bool $allowDocuments = false): FileUpload
    {
        $unknown = array_diff(array_keys($spec), ['url', 'base64', 'name']);
        if ($unknown !== []) {
            throw new ToolException('Поле ' . $field . ': неизвестные ключи ' . implode(', ', $unknown) . '. Нужны url или base64 (и необязательно name).');
        }
        $hasUrl = isset($spec['url']);
        $hasData = isset($spec['base64']);
        if ($hasUrl === $hasData) {
            throw new ToolException('Поле ' . $field . ': передайте ровно одно из url или base64.');
        }

        if ($hasUrl) {
            if (!is_string($spec['url']) || $this->downloader === null) {
                throw new ToolException('Поле ' . $field . ': загрузка по url недоступна, используйте base64.');
            }
            $bytes = $this->downloader->download($spec['url'], self::MAX_BYTES);
            $source = (string) parse_url($spec['url'], PHP_URL_PATH);
        } else {
            if (!is_string($spec['base64'])) {
                throw new ToolException('Поле ' . $field . ': base64 должен быть строкой.');
            }
            $bytes = base64_decode(preg_replace('/^data:[^,]*,/', '', trim($spec['base64'])) ?? '', true);
            if ($bytes === false || $bytes === '') {
                throw new ToolException('Поле ' . $field . ': base64 не декодируется.');
            }
            $source = '';
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ToolException('Поле ' . $field . ': файл больше ' . intdiv(self::MAX_BYTES, 1048576) . ' МБ.');
        }

        $name = $spec['name'] ?? basename($source);
        if (!is_string($name)) {
            throw new ToolException('Поле ' . $field . ': name должно быть строкой.');
        }

        if ($allowDocuments && str_starts_with($bytes, '%PDF-')) {
            return new FileUpload($this->safeName($name, 'pdf', 'document'), 'application/pdf', $bytes, 0, 0);
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!isset(self::TYPES[$mime]) || $info[0] < 1 || $info[1] < 1) {
            throw new ToolException('Поле ' . $field . ': это не JPEG, PNG, WebP' . ($allowDocuments ? ', GIF или PDF.' : ' или GIF.'));
        }

        return new FileUpload($this->safeName($name, self::TYPES[$mime]), $mime, $bytes, (int) $info[0], (int) $info[1]);
    }

    /** Имя файла только из безопасных символов, расширение всегда по реальному типу. */
    private function safeName(string $name, string $extension, string $fallback = 'image'): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $base), '-');

        return ($base !== '' ? substr($base, 0, 80) : $fallback) . '.' . $extension;
    }
}
