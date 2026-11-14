<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

use ZipArchive;

/**
 * Безопасная установка архива модуля поверх текущей версии: проверка структуры и версии,
 * резервная копия, замена файлов и откат при любой ошибке.
 */
final class ZipInstaller
{
    private const MAX_FILES = 1000;
    private const MAX_TOTAL = 20971520;
    private const MAX_FILE = 5242880;
    private const EXTENSIONS = ['php', 'md', 'txt', 'json', 'css', 'js', 'svg', 'example', 'htaccess'];

    /** @param (callable(string):void)|null $afterApply вызывается после замены файлов, получает папку модуля */
    /** Относительный путь рабочей папки внутри папки модуля (например «_updates»); её содержимое не копируется и не удаляется. */
    private ?string $skip = null;

    public function __construct(private PackageProfile $profile, private $afterApply = null)
    {
    }

    public function install(string $zipPath, string $moduleDir, string $workDir, string $expectedVersion, string $currentVersion): void
    {
        $moduleDir = rtrim($moduleDir, '/');
        if (!is_dir($moduleDir)) {
            throw new UpdateException('Папка модуля не найдена.');
        }
        $this->ensureDir($workDir);
        $this->denyWebAccess($workDir);
        $realModule = realpath($moduleDir) ?: $moduleDir;
        $realWork = realpath($workDir) ?: $workDir;
        $this->skip = str_starts_with($realWork, $realModule . '/') ? substr($realWork, strlen($realModule) + 1) : null;

        $lock = fopen($workDir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new UpdateException('Обновление уже выполняется.');
        }

        $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $staging = $workDir . '/staging-' . $stamp;
        $backup = $workDir . '/backup-' . $currentVersion . '-' . $stamp;

        try {
            $this->extractSafely($zipPath, $staging);
            $newRoot = $staging . '/' . $this->profile->root;
            $this->assertPackage($newRoot, $expectedVersion, $currentVersion);

            $this->copyTree($moduleDir, $backup);
            try {
                $this->syncTree($newRoot, $moduleDir);
                if ($this->afterApply !== null) {
                    ($this->afterApply)($moduleDir);
                }
            } catch (\Throwable $exception) {
                $this->syncTree($backup, $moduleDir);
                if ($this->afterApply !== null) {
                    ($this->afterApply)($moduleDir);
                }
                throw new UpdateException('Обновление не применено, восстановлена прежняя версия: ' . $exception->getMessage());
            }
            $this->pruneBackups($workDir, 3);
        } finally {
            $this->removeTree($staging);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function extractSafely(string $zipPath, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('Архив повреждён или не является zip.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
                throw new UpdateException('Недопустимое число файлов в архиве.');
            }
            $total = 0;
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) $stat['name'];
                $this->assertSafeName($name);
                $isDir = str_ends_with($name, '/');
                if (!$isDir) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $base = basename($name);
                    if (!in_array($ext, self::EXTENSIONS, true) && $base !== '.htaccess') {
                        throw new UpdateException('Недопустимый тип файла в архиве: ' . $name);
                    }
                    if ($stat['size'] > self::MAX_FILE) {
                        throw new UpdateException('Файл слишком большой: ' . $name);
                    }
                    $total += (int) $stat['size'];
                    if ($total > self::MAX_TOTAL) {
                        throw new UpdateException('Архив слишком большой после распаковки.');
                    }
                }
                $entries[] = [$i, $name, $isDir];
            }

            $this->ensureDir($target);
            foreach ($entries as [$index, $name, $isDir]) {
                $path = $target . '/' . $name;
                if ($isDir) {
                    $this->ensureDir($path);
                    continue;
                }
                $this->ensureDir(dirname($path));
                $content = $zip->getFromIndex($index);
                if ($content === false || file_put_contents($path, $content) === false) {
                    throw new UpdateException('Не удалось распаковать: ' . $name);
                }
                @chmod($path, 0644);
            }
        } finally {
            $zip->close();
        }
    }

    private function assertSafeName(string $name): void
    {
        $valid = $name !== ''
            && !str_contains($name, "\0")
            && !str_contains($name, '\\')
            && !str_contains($name, '//')
            && !str_starts_with($name, '/')
            && !preg_match('#(^|/)\.{1,2}(/|$)#', $name)
            && preg_match('#^' . preg_quote($this->profile->root, '#') . '(/[A-Za-z0-9_.\-]+)*/?$#', $name) === 1;
        if (!$valid) {
            throw new UpdateException('Небезопасный путь в архиве: ' . $name);
        }
    }

    private function assertPackage(string $root, string $expectedVersion, string $currentVersion): void
    {
        foreach ($this->profile->required as $required) {
            if (!file_exists($root . '/' . $required)) {
                throw new UpdateException('В архиве нет обязательного файла: ' . $required);
            }
        }
        $version = ModuleInfo::version($root, $this->profile->versionFile);
        if ($version !== $expectedVersion) {
            throw new UpdateException('Версия в архиве (' . $version . ') не совпадает с версией релиза (' . $expectedVersion . ').');
        }
        if (version_compare($version, $currentVersion, '<=')) {
            throw new UpdateException('Версия ' . $version . ' не новее установленной (' . $currentVersion . ').');
        }
    }

    private function copyTree(string $from, string $to): void
    {
        $this->ensureDir($to);
        foreach ($this->listFiles($from) as $relative) {
            $target = $to . '/' . $relative;
            $this->ensureDir(dirname($target));
            if (!copy($from . '/' . $relative, $target)) {
                throw new UpdateException('Не удалось скопировать: ' . $relative);
            }
        }
    }

    /** Приводит $to к содержимому $from: копирует всё, лишние файлы удаляет. */
    private function syncTree(string $from, string $to): void
    {
        $wanted = $this->listFiles($from);
        foreach ($wanted as $relative) {
            $target = $to . '/' . $relative;
            $this->ensureDir(dirname($target));
            if (!copy($from . '/' . $relative, $target)) {
                throw new UpdateException('Не удалось записать: ' . $relative);
            }
            @chmod($target, 0644);
        }
        $keep = array_flip($wanted);
        foreach ($this->listFiles($to) as $relative) {
            if (!isset($keep[$relative])) {
                @unlink($to . '/' . $relative);
            }
        }
    }

    /** @return list<string> относительные пути файлов; символические ссылки не обходятся */
    private function listFiles(string $root): array
    {
        $result = [];
        if (!is_dir($root)) {
            return $result;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
            if ($this->skip !== null && ($relative === $this->skip || str_starts_with($relative, $this->skip . '/'))) {
                continue;
            }
            $result[] = $relative;
        }
        sort($result);

        return $result;
    }

    private function pruneBackups(string $workDir, int $keep): void
    {
        $dirs = glob($workDir . '/backup-*', GLOB_ONLYDIR) ?: [];
        sort($dirs);
        foreach (array_slice($dirs, 0, max(0, count($dirs) - $keep)) as $dir) {
            $this->removeTree($dir);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** Резервные копии кода не должны отдаваться веб-сервером. */
    private function denyWebAccess(string $dir): void
    {
        $file = $dir . '/.htaccess';
        if (!is_file($file)) {
            file_put_contents($file, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UpdateException('Не удалось создать папку: ' . $dir);
        }
    }
}
