<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/BitrixStubs.php';

use OrgBox\SiteOps\Bootstrap;
use OrgBox\SiteOps\Config\BitrixOptionStore;
use OrgBox\SiteOps\Update\PackageProfile;
use OrgBox\SiteOps\Update\UpdateException;
use OrgBox\SiteOps\Update\ReleaseVerifier;
use OrgBox\SiteOps\Update\Updater;
use OrgBox\SiteOps\Update\ZipInstaller;
use Tests\Support\FakeHttp;

/** Собирает оба архива релиза в временную папку и возвращает путь. */
function buildRelease(): string
{
    $out = tempDir();
    exec('php ' . escapeshellarg(__DIR__ . '/../tools/build-release.php') . ' ' . escapeshellarg($out) . ' 2>&1', $lines, $code);
    assertSame(0, $code, implode("\n", $lines));

    return $out;
}

function releaseVersion(): string
{
    $arModuleVersion = [];
    require __DIR__ . '/../modules/orgbox.siteops/install/version.php';

    return $arModuleVersion['VERSION'];
}

/** Папка site.ops «установленной» старой версии (раскладка 0.3.0: точки входа в корне) с рабочей папкой _updates внутри. */
function makeSiteDir(string $version): string
{
    $dir = tempDir() . '/site.ops';
    mkdir($dir . '/lib/OrgBox/SiteOps', 0755, true);
    foreach (['mcp.php', 'api.php', 'admin.php', 'autoload.php', '.htaccess'] as $file) {
        file_put_contents($dir . '/' . $file, "<?php // old $file\n");
    }
    file_put_contents($dir . '/version.php', "<?php\n\$arModuleVersion = ['VERSION' => '$version', 'VERSION_DATE' => '2026-01-01 00:00:00'];\n");
    file_put_contents($dir . '/lib/OrgBox/SiteOps/Old.php', "<?php // удалится\n");

    return $dir;
}

// ---- настройки по сайтам ------------------------------------------------------------------------

test('настройки: режим модуля — общие, режим site.ops — отдельные для каждого сайта', function (): void {
    Bitrix\Main\Config\Option::$store = [];
    (new BitrixOptionStore(''))->set('mode', 'readonly');
    (new BitrixOptionStore('s1'))->set('mode', 'readwrite');
    (new BitrixOptionStore('s2'))->set('token_hash', 'hash-s2');

    assertSame('readonly', (new BitrixOptionStore(''))->get('mode'));
    assertSame('readwrite', (new BitrixOptionStore('s1'))->get('mode'));
    assertSame('hash-s2', (new BitrixOptionStore('s2'))->get('token_hash'));
    assertSame('', (new BitrixOptionStore('s1'))->get('token_hash', ''), 'токен сайта s2 не виден сайту s1');
    assertSame('', (new BitrixOptionStore(''))->get('token_hash', ''), 'и общим настройкам');
    assertSame('readwrite', (new BitrixOptionStore('s1'))->get('mode'), 'режим s1 не перезаписан общим значением');
});

test('Bootstrap: standalone берёт SITE_ID, определяет форму установки и рабочую папку', function (): void {
    if (!defined('SITE_ID')) {
        define('SITE_ID', 's2');
    }
    Bitrix\Main\Config\Option::$store = [];
    $standalone = Bootstrap::standalone();
    assertTrue($standalone->isStandalone());
    assertSame('site.ops', $standalone->profile()->root);
    assertSame($standalone->baseDir() . '/_updates', $standalone->workDir());
    $standalone->settings()->generateToken();
    assertTrue((new BitrixOptionStore(SITE_ID))->get('token_hash') !== '', 'токен записан именно для текущего сайта');
    assertSame('', (new BitrixOptionStore(''))->get('token_hash', ''), 'и не попал в общие настройки');

    $module = Bootstrap::module();
    assertTrue(!$module->isStandalone());
    assertSame(releaseVersion(), $module->version());
});

// ---- сборка и установка site.ops ----------------------------------------------------------------

test('сборка: оба архива созданы, суммы совпадают с файлами', function (): void {
    $out = buildRelease();
    try {
        $v = releaseVersion();
        foreach (["orgbox.siteops-$v.zip", "site.ops-$v.zip"] as $name) {
            assertTrue(is_file($out . '/' . $name), $name);
            assertContains(hash_file('sha256', $out . '/' . $name), (string) file_get_contents($out . '/' . $name . '.sha256'));
        }
    } finally {
        removeDir($out);
    }
});

test('сборка: site.ops содержит точки входа, .htaccess, version.php и все классы модуля', function (): void {
    $out = buildRelease();
    try {
        $zip = new ZipArchive();
        $zip->open($out . '/site.ops-' . releaseVersion() . '.zip');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        foreach (['mcp/index.php', 'api/index.php', 'admin/index.php', 'oauth/authorize/index.php', 'oauth/token/index.php', 'oauth/register/index.php', 'oauth/revoke/index.php',
            'oauth/.well-known/oauth-authorization-server/index.php', 'oauth/.well-known/openid-configuration/index.php', 'oauth/.well-known/oauth-protected-resource/index.php',
            '.htaccess', 'mcp/.htaccess', 'oauth/.htaccess', 'autoload.php', 'version.php', 'lib/OrgBox/SiteOps/Bootstrap.php', 'lib/OrgBox/SiteOps/Admin/settings.view.php', 'lib/OrgBox/SiteOps/Admin/authorize.view.php'] as $file) {
            assertTrue(in_array('site.ops/' . $file, $names, true), $file);
        }
        $moduleClasses = array_map(static fn ($f) => substr($f, strlen(__DIR__ . '/../modules/orgbox.siteops/')), glob(__DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/*/*.php') ?: []);
        assertTrue(!in_array('site.ops/mcp.php', $names, true) && !in_array('site.ops/admin.php', $names, true), 'старых точек входа с .php в корне быть не должно');
        foreach ($moduleClasses as $relative) {
            assertTrue(in_array('site.ops/' . $relative, $names, true), $relative);
        }
        // Секретов в архиве нет: только исходный код.
        assertTrue(!in_array('site.ops/config.php', $names, true));
    } finally {
        removeDir($out);
    }
});

test('сборка: собранный site.ops самодостаточен — все классы подгружаются своим автозагрузчиком', function (): void {
    $out = buildRelease();
    try {
        $extract = tempDir();
        $zip = new ZipArchive();
        $zip->open($out . '/site.ops-' . releaseVersion() . '.zip');
        $zip->extractTo($extract);
        $zip->close();

        $script = $extract . '/check.php';
        // Отдельный процесс без нашего автозагрузчика тестов: используется только autoload.php из пакета.
        file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1] . '/site.ops/autoload.php';
        $bad = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1] . '/site.ops/lib', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getFilename(), '.view.php')) { continue; }
            $rel = substr($file->getPathname(), strlen($argv[1] . '/site.ops/lib/'));
            $class = str_replace('/', '\\', substr($rel, 0, -4));
            if (!class_exists($class) && !interface_exists($class)) { $bad[] = $class; }
        }
        echo $bad === [] ? 'OK' : 'НЕ НАЙДЕНЫ: ' . implode(', ', $bad);
        PHP);
        exec('php ' . escapeshellarg($script) . ' ' . escapeshellarg($extract) . ' 2>&1', $lines, $code);
        assertSame('OK', implode("\n", $lines));
        removeDir($extract);
    } finally {
        removeDir($out);
    }
});

test('site.ops: установка нового релиза поверх старого с рабочей папкой _updates внутри', function (): void {
    $out = buildRelease();
    $dir = makeSiteDir('0.0.1');
    try {
        $work = $dir . '/_updates';
        mkdir($work, 0755, true);
        file_put_contents($work . '/keep-me.txt', 'не трогать');
        $zip = (string) file_get_contents($out . '/site.ops-' . releaseVersion() . '.zip');
        file_put_contents(dirname($dir) . '/pkg.zip', $zip);

        (new ZipInstaller(PackageProfile::standalone()))->install(dirname($dir) . '/pkg.zip', $dir, $work, releaseVersion(), '0.0.1');

        assertContains(releaseVersion(), (string) file_get_contents($dir . '/version.php'));
        assertTrue(!file_exists($dir . '/lib/OrgBox/SiteOps/Old.php'), 'устаревший файл удалён');
        foreach (['mcp.php', 'api.php', 'admin.php'] as $legacy) {
            assertTrue(!file_exists($dir . '/' . $legacy), 'обновление с раскладки 0.3.0 удаляет старую точку входа ' . $legacy);
        }
        assertTrue(is_file($dir . '/mcp/index.php') && is_file($dir . '/admin/index.php') && is_file($dir . '/oauth/token/index.php'), 'новые точки входа установлены');
        assertContains('Require all denied', (string) file_get_contents($dir . '/.htaccess'));
        assertSame('не трогать', (string) file_get_contents($work . '/keep-me.txt'), 'рабочая папка не затронута');

        $backups = glob($work . '/backup-0.0.1-*', GLOB_ONLYDIR);
        assertSame(1, count($backups));
        assertTrue(is_file($backups[0] . '/lib/OrgBox/SiteOps/Old.php'), 'в резервной копии старый код');
        assertTrue(!is_dir($backups[0] . '/_updates'), 'резервная копия не вложена сама в себя');
        assertContains('Deny from all', (string) file_get_contents($work . '/.htaccess'), 'рабочая папка закрыта от веба');
    } finally {
        removeDir($out);
        removeDir(dirname($dir));
    }
});

test('site.ops: откат восстанавливает прежнюю версию и не трогает _updates', function (): void {
    $out = buildRelease();
    $dir = makeSiteDir('0.0.1');
    try {
        $work = $dir . '/_updates';
        mkdir($work, 0755, true);
        file_put_contents(dirname($dir) . '/pkg.zip', (string) file_get_contents($out . '/site.ops-' . releaseVersion() . '.zip'));
        $calls = 0;
        $installer = new ZipInstaller(PackageProfile::standalone(), function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('сбой');
            }
        });
        assertThrows(UpdateException::class, fn () => $installer->install(dirname($dir) . '/pkg.zip', $dir, $work, releaseVersion(), '0.0.1'), 'восстановлена');
        assertContains("'0.0.1'", (string) file_get_contents($dir . '/version.php'));
        assertTrue(is_file($dir . '/lib/OrgBox/SiteOps/Old.php'));
        assertTrue(is_dir($work), '_updates на месте');
    } finally {
        removeDir($out);
        removeDir(dirname($dir));
    }
});

test('site.ops: архив модуля не устанавливается как site.ops и наоборот (разные корни)', function (): void {
    $out = buildRelease();
    $dir = makeSiteDir('0.0.1');
    try {
        $moduleZip = $out . '/orgbox.siteops-' . releaseVersion() . '.zip';
        assertThrows(UpdateException::class, fn () => (new ZipInstaller(PackageProfile::standalone()))->install($moduleZip, $dir, dirname($dir) . '/w', releaseVersion(), '0.0.1'), 'Небезопасный путь');
        $siteZip = $out . '/site.ops-' . releaseVersion() . '.zip';
        $moduleDir = makeModuleDir('0.0.1');
        assertThrows(UpdateException::class, fn () => (new ZipInstaller(PackageProfile::module()))->install($siteZip, $moduleDir, dirname($moduleDir) . '/w', releaseVersion(), '0.0.1'), 'Небезопасный путь');
        removeDir(dirname($moduleDir));
    } finally {
        removeDir($out);
        removeDir(dirname($dir));
    }
});

test('site.ops: Updater ищет в релизе архив site.ops-<версия>.zip', function (): void {
    $dir = makeSiteDir('0.0.1');
    try {
        $asset = static fn (string $n): array => ['name' => $n, 'browser_download_url' => "https://github.com/o/r/releases/download/v9.9.9/$n"];
        $http = new FakeHttp(['https://api.github.com/repos/o/r/releases/latest' => json_encode(['tag_name' => 'v9.9.9', 'assets' => [
            $asset('orgbox.siteops-9.9.9.zip'), $asset('orgbox.siteops-9.9.9.zip.sha256'),
            $asset('site.ops-9.9.9.zip'), $asset('site.ops-9.9.9.zip.sha256'), $asset('site.ops-9.9.9.zip.sig'),
        ]])]);
        $updater = new Updater($http, new ReleaseVerifier('', true), new ZipInstaller(PackageProfile::standalone()), PackageProfile::standalone(), 'o/r', '', $dir, $dir . '/_updates', '0.0.1');
        $release = $updater->checkLatest();
        assertContains('site.ops-9.9.9.zip', $release->zipUrl);
        assertContains('site.ops-9.9.9.zip.sig', (string) $release->signatureUrl);

        $onlyModule = new FakeHttp(['https://api.github.com/repos/o/r/releases/latest' => json_encode(['tag_name' => 'v9.9.9', 'assets' => [
            $asset('orgbox.siteops-9.9.9.zip'), $asset('orgbox.siteops-9.9.9.zip.sha256'),
        ]])]);
        $updater2 = new Updater($onlyModule, new ReleaseVerifier('', true), new ZipInstaller(PackageProfile::standalone()), PackageProfile::standalone(), 'o/r', '', $dir, $dir . '/_updates', '0.0.1');
        assertThrows(UpdateException::class, fn () => $updater2->checkLatest(), 'site.ops-9.9.9.zip');
    } finally {
        removeDir(dirname($dir));
    }
});

test('site.ops: точки входа ссылаются только на существующие классы, а подключаемые файлы существуют', function (): void {
    $root = __DIR__ . '/../standalone/site.ops';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $count = 0;
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $count++;
        $code = (string) file_get_contents($file->getPathname());
        $name = substr($file->getPathname(), strlen($root) + 1);
        preg_match_all('/\\\\?OrgBox\\\\SiteOps\\\\[A-Za-z\\\\]+/', $code, $m);
        foreach (array_unique($m[0]) as $class) {
            $class = ltrim($class, '\\');
            assertTrue(class_exists($class) || interface_exists($class), $name . ' → ' . $class);
        }
        // require __DIR__ . '/../..': путь, нормализованный от папки файла, должен указывать на реальный файл пакета.
        // lib/ в пакете берётся из модуля (build-release копирует modules/orgbox.siteops/lib → site.ops/lib).
        preg_match_all("#__DIR__ \\. '(/[^']+\\.php)'#", $code, $paths);
        foreach ($paths[1] as $relative) {
            $parts = [];
            foreach (explode('/', $file->getPath() . $relative) as $segment) {
                if ($segment === '..') {
                    array_pop($parts);
                } elseif ($segment !== '.' && $segment !== '') {
                    $parts[] = $segment;
                }
            }
            $target = '/' . implode('/', $parts);
            $rootReal = '/' . implode('/', array_filter(explode('/', (string) realpath($root)), 'strlen'));
            $moduleLib = (string) realpath(__DIR__ . '/../modules/orgbox.siteops/lib');
            $exists = str_starts_with($target, $rootReal . '/lib/')
                ? is_file($moduleLib . substr($target, strlen($rootReal . '/lib')))
                : is_file($target);
            assertTrue($exists, $name . ' → ' . $relative . ' (' . $target . ')');
        }
    }
    assertTrue($count >= 11, 'найдено точек входа: ' . $count);
    assertTrue(is_file(__DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/Admin/settings.view.php'));
    assertTrue(is_file(__DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/Admin/authorize.view.php'));
});

test('site.ops: .htaccess — по умолчанию всё закрыто, открыты только папки точек входа, FilesMatch не используется', function (): void {
    $root = __DIR__ . '/../standalone/site.ops';
    $main = (string) file_get_contents($root . '/.htaccess');
    assertContains('Require all denied', $main);
    assertContains('Deny from all', $main);
    assertTrue(!str_contains($main, 'FilesMatch') && !str_contains($main, 'Require all granted'),
        'FilesMatch в корне открыл бы index.php во вложенных папках (например, в копиях _updates)');
    // Все index.php лежат только в папках с собственным разрешающим .htaccess
    $entry = ['mcp', 'api', 'admin', 'oauth'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getFilename() !== 'index.php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $top = explode('/', $relative)[0];
        assertTrue(in_array($top, $entry, true), 'index.php вне папок точек входа: ' . $relative);
        assertContains('Require all granted', (string) file_get_contents($root . '/' . $top . '/.htaccess'), $top);
    }
    assertContains('DirectorySlash Off', $main, 'без этого Apache редиректит /site.ops/mcp на /site.ops/mcp/ и POST теряет тело');
    assertContains('^(mcp|api|admin)/?$ $1/index.php', $main);
    assertContains('oauth/(authorize|token|register|revoke)/?$', $main);
    assertContains('oauth-authorization-server|openid-configuration|oauth-protected-resource', $main, 'документы обнаружения без слэша');
    assertContains('HTTP_AUTHORIZATION', $main, 'заголовок Authorization передаётся в PHP');
});

test('site.ops: рабочая папка обновлений закрывается так, чтобы index.php в копиях не открывался', function (): void {
    $dir = makeSiteDir('0.0.1');
    $out = buildRelease();
    try {
        $work = $dir . '/_updates';
        file_put_contents(dirname($dir) . '/pkg.zip', (string) file_get_contents($out . '/site.ops-' . releaseVersion() . '.zip'));
        (new ZipInstaller(PackageProfile::standalone()))->install(dirname($dir) . '/pkg.zip', $dir, $work, releaseVersion(), '0.0.1');
        $htaccess = (string) file_get_contents($work . '/.htaccess');
        assertContains('Deny from all', $htaccess);
        assertContains('FilesMatch', $htaccess, 'явный запрет на все файлы, включая index.php копий');
    } finally {
        removeDir($out);
        removeDir(dirname($dir));
    }
});

test('документация: фрагмент корневого .well-known в INSTALL.md совпадает с файлом, который проверяет Apache-скрипт', function (): void {
    $install = (string) file_get_contents(__DIR__ . '/../docs/INSTALL.md');
    foreach (file(__DIR__ . '/../standalone/root-well-known.htaccess', FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, 'RewriteRule') || str_starts_with($line, 'RewriteEngine')) {
            assertContains($line, $install, 'в INSTALL.md нет строки: ' . $line);
        }
    }
});

test('документация: во всех документах нет упоминаний старых адресов site.ops/*.php', function (): void {
    foreach (array_merge(glob(__DIR__ . '/../docs/*.md') ?: [], [__DIR__ . '/../README.md']) as $file) {
        $text = (string) file_get_contents($file);
        foreach (['/site.ops/mcp.php', '/site.ops/admin.php', '/site.ops/api.php'] as $old) {
            // допустимо только в разделе перехода с 0.3.x и в описании старых адресов
            if (str_contains($text, $old)) {
                assertTrue(str_contains($text, '0.3'), basename($file) . ' упоминает ' . $old . ' без пометки о версии 0.3');
            }
        }
    }
});
