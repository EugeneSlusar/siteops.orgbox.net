<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Config;

use RuntimeException;

/**
 * Настройки в файле внутри папки site.ops (_data/settings.php). Папка у каждого сайта своя, поэтому настройки,
 * токен и подключения разных сайтов не смешиваются, и от идентификатора сайта Битрикса (SITE_ID, который в админке
 * и на публичной странице может различаться) ничего не зависит.
 *
 * Файл начинается со строки-заглушки <?php ... exit; ?>: даже если веб-сервер отдаст его как скрипт, содержимое не показывается.
 * Запись атомарная (временный файл и rename) и под блокировкой; чтение не использует include, поэтому opcache не мешает.
 */
final class FileOptionStore implements OptionStore
{
    private const GUARD = "<?php http_response_code(404); exit; ?>\n";

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private string $file)
    {
    }

    public function path(): string
    {
        return $this->file;
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->load()[$name] ?? $default;
    }

    public function set(string $name, string $value): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать папку настроек: ' . $dir);
        }
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Не удалось заблокировать файл настроек: ' . $dir);
        }
        try {
            $this->cache = null; // читаем заново под блокировкой: другой запрос мог записать раньше
            $values = $this->load();
            $values[$name] = $value;
            $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, self::GUARD . json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
                throw new RuntimeException('Не удалось записать настройки: ' . $tmp);
            }
            @chmod($tmp, 0640);
            if (!@rename($tmp, $this->file)) {
                @unlink($tmp);
                throw new RuntimeException('Не удалось сохранить файл настроек: ' . $this->file);
            }
            $this->cache = $values;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Можно ли сейчас записывать настройки (для диагностики на странице). */
    public function isWritable(): bool
    {
        $dir = dirname($this->file);
        if (is_dir($dir)) {
            return is_writable($dir) && (!is_file($this->file) || is_writable($this->file));
        }

        return is_writable(dirname($dir));
    }

    /** @return array<string,string> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = [];
        if (!is_file($this->file)) {
            return $this->cache;
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            throw new RuntimeException('Не удалось прочитать файл настроек: ' . $this->file);
        }
        if (str_starts_with($raw, self::GUARD)) {
            $raw = substr($raw, strlen(self::GUARD));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Файл настроек повреждён: ' . $this->file . ' (удалите его, чтобы начать с настроек по умолчанию).');
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $this->cache[$key] = (string) $value;
            }
        }

        return $this->cache;
    }
}
