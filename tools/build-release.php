<?php

declare(strict_types=1);

/**
 * Собирает архив модуля для релиза: dist/orgbox.siteops-<версия>.zip и .sha256.
 * Использование: php tools/build-release.php [папка-вывода]
 */

$root = dirname(__DIR__);
$moduleDir = $root . '/modules/orgbox.siteops';
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
$zipPath = $out . '/orgbox.siteops-' . $version . '.zip';
@unlink($zipPath);

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && !$file->isLink()) {
        $files[] = substr($file->getPathname(), strlen($moduleDir) + 1);
    }
}
sort($files);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Не удалось создать архив\n");
    exit(1);
}
foreach ($files as $index => $relative) {
    $name = 'orgbox.siteops/' . $relative;
    $zip->addFile($moduleDir . '/' . $relative, $name);
    if (method_exists($zip, 'setMtimeName')) {
        $zip->setMtimeName($name, 946684800); // 2000-01-01: сборка воспроизводима
    }
}
$zip->close();

$sha = hash_file('sha256', $zipPath);
file_put_contents($zipPath . '.sha256', $sha . '  ' . basename($zipPath) . "\n");
echo "Собрано: $zipPath\nSHA-256: $sha\nФайлов: " . count($files) . "\n";
