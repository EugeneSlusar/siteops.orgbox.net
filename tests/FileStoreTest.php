<?php

declare(strict_types=1);

use OrgBox\SiteOps\Config\FileOptionStore;
use OrgBox\SiteOps\Config\Settings;
use OrgBox\SiteOps\Update\PackageProfile;
use OrgBox\SiteOps\Update\UpdateException;
use OrgBox\SiteOps\Update\ZipInstaller;

function withStoreDir(callable $body): void
{
    $dir = tempDir();
    try {
        $body($dir . '/_data/settings.php', $dir);
    } finally {
        removeDir($dir);
    }
}

test('файл настроек: значения сохраняются и читаются другим экземпляром (как другой запрос)', function (): void {
    withStoreDir(function (string $file): void {
        $store = new FileOptionStore($file);
        assertSame('x', $store->get('mcp_enabled', 'x'), 'без файла — значение по умолчанию');
        $store->set('mcp_enabled', 'Y');
        $store->set('oauth_enabled', 'Y');
        $store->set('allowed_iblocks', '5,6');
        $other = new FileOptionStore($file);
        assertSame('Y', $other->get('mcp_enabled'));
        assertSame('Y', $other->get('oauth_enabled'));
        assertSame('5,6', $other->get('allowed_iblocks'));
        $store->set('mcp_enabled', 'N');
        assertSame('N', (new FileOptionStore($file))->get('mcp_enabled'), 'значение перезаписывается, остальные не теряются');
        assertSame('Y', (new FileOptionStore($file))->get('oauth_enabled'));
    });
});

test('файл настроек: через Settings включение MCP и OAuth переживает «перезагрузку страницы»', function (): void {
    withStoreDir(function (string $file): void {
        $store = new FileOptionStore($file);
        $store->set('mcp_enabled', 'Y');
        $store->set('oauth_enabled', 'Y');
        $reloaded = new Settings(new FileOptionStore($file));
        assertTrue($reloaded->mcpEnabled());
        assertTrue($reloaded->oauthEnabled());
        $store->set('oauth_enabled', 'N');
        assertTrue(!(new Settings(new FileOptionStore($file)))->oauthEnabled());
    });
});

test('файл настроек: начинается со строки-заглушки и не содержит токен в открытом виде', function (): void {
    withStoreDir(function (string $file): void {
        $settings = new Settings(new FileOptionStore($file));
        $token = $settings->generateToken();
        $raw = (string) file_get_contents($file);
        assertTrue(str_starts_with($raw, "<?php http_response_code(404); exit; ?>\n"), 'заглушка: файл не покажет содержимое, если его выполнит PHP');
        assertTrue(!str_contains($raw, $token), 'токен в открытом виде не хранится');
        assertContains(hash('sha256', $token), $raw);
        // Выполнение файла как скрипта ничего не выводит
        exec('php ' . escapeshellarg($file) . ' 2>&1', $lines);
        assertSame('', trim(implode("\n", $lines)), 'выполнение файла как скрипта не должно ничего выводить');
        assertTrue(((int) (fileperms($file) & 0777)) === 0640, 'права 0640');
    });
});

test('файл настроек: нестандартные символы, кириллица и косые черты сохраняются как есть', function (): void {
    withStoreDir(function (string $file): void {
        $store = new FileOptionStore($file);
        $value = "https://shop.example/путь?a=1&b=\"2\" и 'кавычки'\nвторая строка";
        $store->set('public_url', $value);
        assertSame($value, (new FileOptionStore($file))->get('public_url'));
    });
});

test('файл настроек: повреждённый файл даёт понятную ошибку, а не молчаливый сброс', function (): void {
    withStoreDir(function (string $file): void {
        mkdir(dirname($file), 0750, true);
        file_put_contents($file, "<?php http_response_code(404); exit; ?>\n{оборванный json");
        assertThrows(RuntimeException::class, fn () => (new FileOptionStore($file))->get('mcp_enabled'), 'повреждён');
        assertThrows(RuntimeException::class, fn () => (new FileOptionStore($file))->set('mcp_enabled', 'Y'), 'повреждён');
    });
});

test('файл настроек: нельзя записать — исключение с путём, а не тихая потеря', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        // root обходит права файловой системы; проверяем заведомо невозможный путь
        $store = new FileOptionStore('/proc/siteops-no-such/_data/settings.php');
        assertThrows(RuntimeException::class, fn () => $store->set('a', 'b'), 'Не удалось');
        assertTrue(!$store->isWritable());

        return;
    }
    withStoreDir(function (string $file, string $dir): void {
        mkdir(dirname($file), 0750, true);
        chmod(dirname($file), 0500);
        $store = new FileOptionStore($file);
        assertTrue(!$store->isWritable());
        assertThrows(RuntimeException::class, fn () => $store->set('a', 'b'));
        chmod(dirname($file), 0750);
    });
});

test('файл настроек: параллельные записи разных ключей не теряют друг друга', function (): void {
    withStoreDir(function (string $file): void {
        $script = tempDir() . '/w.php';
        file_put_contents($script, '<?php require ' . var_export(__DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/Config/OptionStore.php', true)
            . '; require ' . var_export(__DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/Config/FileOptionStore.php', true)
            . '; $s = new OrgBox\SiteOps\Config\FileOptionStore($argv[1]); for ($i = 0; $i < 25; $i++) { $s->set("k_" . $argv[2] . "_" . $i, "v"); }');
        $procs = [];
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $procs[] = proc_open(['php', $script, $file, $id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($procs as $p) {
            proc_close($p);
        }
        $store = new FileOptionStore($file);
        $missing = 0;
        foreach (['a', 'b', 'c', 'd'] as $id) {
            for ($i = 0; $i < 25; $i++) {
                if ($store->get("k_{$id}_{$i}", '') !== 'v') {
                    $missing++;
                }
            }
        }
        assertSame(0, $missing, 'потеряно записей при параллельной работе: ' . $missing);
        removeDir(dirname($script));
    });
});

test('обновление site.ops не трогает _data: настройки и токен переживают установку и откат', function (): void {
    $out = buildRelease();
    $dir = makeSiteDir('0.0.1');
    try {
        $settings = new Settings(new FileOptionStore($dir . '/_data/settings.php'));
        $token = $settings->generateToken();
        $settings->store()->set('oauth_enabled', 'Y');
        $before = (string) file_get_contents($dir . '/_data/settings.php');

        $pkg = dirname($dir) . '/pkg.zip';
        file_put_contents($pkg, (string) file_get_contents($out . '/site.ops-' . releaseVersion() . '.zip'));
        (new ZipInstaller(PackageProfile::standalone()))->install($pkg, $dir, $dir . '/_updates', releaseVersion(), '0.0.1');
        assertSame($before, (string) file_get_contents($dir . '/_data/settings.php'), 'файл настроек не изменился при обновлении');
        assertTrue(is_dir($dir . '/_updates'), 'рабочая папка на месте');
        foreach (glob($dir . '/_updates/backup-*', GLOB_ONLYDIR) as $backup) {
            assertTrue(!is_dir($backup . '/_data'), 'настройки (с хэшем токена) не копируются в резервные копии');
        }

        // откат: хук падает, прежняя версия восстанавливается, настройки целы
        $dir2 = makeSiteDir('0.0.1');
        $settings2 = new Settings(new FileOptionStore($dir2 . '/_data/settings.php'));
        $settings2->generateToken();
        $before2 = (string) file_get_contents($dir2 . '/_data/settings.php');
        file_put_contents(dirname($dir2) . '/pkg2.zip', (string) file_get_contents($pkg));
        $calls = 0;
        $failing = new ZipInstaller(PackageProfile::standalone(), function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('сбой');
            }
        });
        assertThrows(UpdateException::class, fn () => $failing->install(dirname($dir2) . '/pkg2.zip', $dir2, $dir2 . '/_updates', releaseVersion(), '0.0.1'), 'восстановлена');
        assertSame($before2, (string) file_get_contents($dir2 . '/_data/settings.php'), 'после отката настройки целы');
        removeDir(dirname($dir2));
    } finally {
        removeDir($out);
        removeDir(dirname($dir));
    }
});

test('сборка: _data и _updates никогда не попадают в архивы (даже если лежат рядом с кодом)', function (): void {
    $planted = [__DIR__ . '/../modules/orgbox.siteops/_data', __DIR__ . '/../standalone/site.ops/_updates'];
    foreach ($planted as $dir) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/settings.php', 'СЕКРЕТ');
    }
    $out = tempDir();
    try {
        exec('php ' . escapeshellarg(__DIR__ . '/../tools/build-release.php') . ' ' . escapeshellarg($out) . ' 2>&1', $lines, $code);
        assertSame(0, $code, implode("\n", $lines));
        foreach (glob($out . '/*.zip') as $zipFile) {
            $zip = new ZipArchive();
            $zip->open($zipFile);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                assertTrue(!str_contains($name, '_data') && !str_contains($name, '_updates'), basename($zipFile) . ' содержит ' . $name);
            }
            $zip->close();
        }
    } finally {
        foreach ($planted as $dir) {
            removeDir($dir);
        }
        removeDir($out);
    }
});
