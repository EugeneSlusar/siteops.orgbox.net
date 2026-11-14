<?php

declare(strict_types=1);

/**
 * Собирает два архива релиза (версия берётся из modules/orgbox.siteops/install/version.php):
 *   dist/orgbox.siteops-<версия>.zip  — формальный модуль Битрикса (/local/modules/orgbox.siteops)
 *   dist/site.ops-<версия>.zip        — папка для публичной части сайта (<корень сайта>/site.ops)
 * и файлы контрольных сумм .sha256. Сборка воспроизводима: одинаковые исходники дают одинаковые архивы.
 *
 * Использование: php tools/build-release.php [папка-вывода]
 */

$root = dirname(__DIR__);
$moduleDir = $root . '/modules/orgbox.siteops';
$standaloneDir = $root . '/standalone/site.ops';
$out = $argv[1] ?? $root . '/dist';

$arModuleVersion = [];
require $moduleDir . '/install/version.php';
$version = (string) $arModuleVersion['VERSION'];
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "Некорректная версия в install/version.php: $version\n");
    exit(1);
}
if (!is_dir($out) && !mkdir($out, 0755, true)) {
    fwrite(STDERR, "Не удалось создать $out\n");
    exit(1);
}

/** @return array<string,string> относительный путь => путь на диске (отсортировано) */
function listTree(string $dir, string $prefix = ''): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $files[$prefix . substr($file->getPathname(), strlen($dir) + 1)] = $file->getPathname();
        }
    }
    ksort($files);

    return $files;
}

/** @param array<string,string> $files имя в архиве => путь на диске */
function writeZip(string $zipPath, array $files): void
{
    @unlink($zipPath);
    ksort($files);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "Не удалось создать $zipPath\n");
        exit(1);
    }
    foreach ($files as $name => $path) {
        $zip->addFile($path, $name);
        if (method_exists($zip, 'setMtimeName')) {
            $zip->setMtimeName($name, 946684800); // 2000-01-01
        }
    }
    $zip->close();
    $sha = hash_file('sha256', $zipPath);
    file_put_contents($zipPath . '.sha256', $sha . '  ' . basename($zipPath) . "\n");
    echo basename($zipPath) . '  ' . count($files) . ' файлов  sha256=' . $sha . "\n";
}

// 1. Формальный модуль
$moduleFiles = [];
foreach (listTree($moduleDir) as $relative => $path) {
    $moduleFiles['orgbox.siteops/' . $relative] = $path;
}
writeZip($out . '/orgbox.siteops-' . $version . '.zip', $moduleFiles);

// 2. Папка site.ops: точки входа и .htaccess из standalone/, классы и версия — из модуля
$siteFiles = [];
foreach (listTree($standaloneDir) as $relative => $path) {
    $siteFiles['site.ops/' . $relative] = $path;
}
foreach (listTree($moduleDir . '/lib') as $relative => $path) {
    $siteFiles['site.ops/lib/' . $relative] = $path;
}
$siteFiles['site.ops/version.php'] = $moduleDir . '/install/version.php';
writeZip($out . '/site.ops-' . $version . '.zip', $siteFiles);
