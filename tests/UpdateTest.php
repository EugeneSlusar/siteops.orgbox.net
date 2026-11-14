<?php

declare(strict_types=1);

use OrgBox\SiteOps\Update\PackageProfile;
use OrgBox\SiteOps\Update\ReleaseVerifier;
use OrgBox\SiteOps\Update\UpdateException;
use OrgBox\SiteOps\Update\Updater;
use OrgBox\SiteOps\Update\ZipInstaller;
use Tests\Support\FakeHttp;

/** Собирает архив модуля с нужной версией; $extra — дополнительные записи (имя => содержимое). */
function makeZip(string $version, array $extra = [], array $omit = []): string
{
    $entries = [
        'orgbox.siteops/include.php' => "<?php // v$version\n",
        'orgbox.siteops/install/index.php' => "<?php // installer\n",
        'orgbox.siteops/install/version.php' => "<?php\n\$arModuleVersion = ['VERSION' => '$version', 'VERSION_DATE' => '2026-01-01 00:00:00'];\n",
        'orgbox.siteops/install/admin/page.php' => "<?php // admin $version\n",
        'orgbox.siteops/lib/OrgBox/SiteOps/A.php' => "<?php // A $version\n",
    ];
    foreach ($omit as $name) {
        unset($entries[$name]);
    }
    $path = tempnam(sys_get_temp_dir(), 'siteops-zip');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries + $extra as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** Установленный модуль заданной версии. */
function makeModuleDir(string $version): string
{
    $dir = tempDir() . '/orgbox.siteops';
    mkdir($dir . '/install', 0755, true);
    mkdir($dir . '/lib/OrgBox/SiteOps', 0755, true);
    file_put_contents($dir . '/include.php', "<?php // v$version\n");
    file_put_contents($dir . '/install/index.php', "<?php // installer\n");
    file_put_contents($dir . '/install/version.php', "<?php\n\$arModuleVersion = ['VERSION' => '$version', 'VERSION_DATE' => '2026-01-01 00:00:00'];\n");
    file_put_contents($dir . '/lib/OrgBox/SiteOps/Old.php', "<?php // удалится при обновлении\n");

    return $dir;
}

function installZip(string $bytes, string $moduleDir, string $version, string $current = '0.1.0', ?callable $after = null, ?PackageProfile $profile = null): void
{
    $work = dirname($moduleDir) . '/work';
    $path = dirname($moduleDir) . '/pkg.zip';
    file_put_contents($path, $bytes);
    (new ZipInstaller($profile ?? PackageProfile::module(), $after))->install($path, $moduleDir, $work, $version, $current);
}

function withCleanup(string $moduleDir, callable $body): void
{
    try {
        $body();
    } finally {
        removeDir(dirname($moduleDir));
    }
}

// ---- проверка подлинности ---------------------------------------------------------------------

function keypair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [base64_encode(sodium_crypto_sign_publickey($pair)), sodium_crypto_sign_secretkey($pair)];
}

test('подпись: корректная подпись и сумма принимаются', function (): void {
    [$public, $secret] = keypair();
    $zip = makeZip('0.2.0');
    $sig = base64_encode(sodium_crypto_sign_detached($zip, $secret));
    assertSame('signed', (new ReleaseVerifier($public, false))->verify($zip, hash('sha256', $zip) . '  x.zip', $sig));
});

test('подпись: неверная контрольная сумма отклоняется', function (): void {
    [$public, $secret] = keypair();
    $zip = makeZip('0.2.0');
    $sig = base64_encode(sodium_crypto_sign_detached($zip, $secret));
    assertThrows(UpdateException::class, fn () => (new ReleaseVerifier($public, false))->verify($zip, str_repeat('0', 64), $sig), 'Контрольная сумма');
});

test('подпись: подменённый архив с пересчитанной суммой отклоняется по подписи', function (): void {
    [$public, $secret] = keypair();
    $good = makeZip('0.2.0');
    $evil = makeZip('0.2.0', ['orgbox.siteops/lib/OrgBox/SiteOps/Evil.php' => '<?php system($_GET["c"]);']);
    $sig = base64_encode(sodium_crypto_sign_detached($good, $secret));
    assertThrows(UpdateException::class, fn () => (new ReleaseVerifier($public, false))->verify($evil, hash('sha256', $evil), $sig), 'Подпись');
});

test('подпись: подпись чужим ключом отклоняется', function (): void {
    [$public] = keypair();
    [, $otherSecret] = keypair();
    $zip = makeZip('0.2.0');
    $sig = base64_encode(sodium_crypto_sign_detached($zip, $otherSecret));
    assertThrows(UpdateException::class, fn () => (new ReleaseVerifier($public, false))->verify($zip, hash('sha256', $zip), $sig), 'Подпись');
});

test('подпись: при настроенном ключе релиз без подписи отклоняется', function (): void {
    [$public] = keypair();
    $zip = makeZip('0.2.0');
    assertThrows(UpdateException::class, fn () => (new ReleaseVerifier($public, true))->verify($zip, hash('sha256', $zip), null), 'нет подписи');
});

test('подпись: без ключа обновления по умолчанию запрещены', function (): void {
    $zip = makeZip('0.2.0');
    assertThrows(UpdateException::class, fn () => (new ReleaseVerifier('', false))->verify($zip, hash('sha256', $zip), null), 'открытый ключ');
});

test('подпись: без ключа можно явно разрешить только SHA-256', function (): void {
    $zip = makeZip('0.2.0');
    assertSame('checksum-only', (new ReleaseVerifier('', true))->verify($zip, hash('sha256', $zip), null));
});

test('подпись: повреждённый файл суммы и мусорный ключ', function (): void {
    $zip = makeZip('0.2.0');
    assertThrows(UpdateException::class, fn () => (new ReleaseVerifier('', true))->verify($zip, 'не сумма', null), 'повреждён');
    assertThrows(UpdateException::class, fn () => (new ReleaseVerifier('мусор', false))->verify($zip, hash('sha256', $zip), 'мусор'), 'формат');
});

// ---- установка архива -------------------------------------------------------------------------

test('установка: файлы заменены, лишние удалены, резервная копия создана, хук вызван', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        $called = [];
        installZip(makeZip('0.2.0'), $dir, '0.2.0', '0.1.0', function (string $d) use (&$called): void {
            $called[] = $d;
        });
        assertContains('0.2.0', (string) file_get_contents($dir . '/include.php'));
        assertContains("'0.2.0'", (string) file_get_contents($dir . '/install/version.php'));
        assertTrue(is_file($dir . '/lib/OrgBox/SiteOps/A.php'));
        assertTrue(!file_exists($dir . '/lib/OrgBox/SiteOps/Old.php'), 'устаревший файл должен быть удалён');
        assertSame([$dir], $called);
        $backups = glob(dirname($dir) . '/work/backup-0.1.0-*');
        assertSame(1, count($backups));
        assertTrue(is_file($backups[0] . '/lib/OrgBox/SiteOps/Old.php'));
        assertSame([], glob(dirname($dir) . '/work/staging-*') ?: [], 'временная папка должна быть удалена');
        assertContains('Deny from all', (string) file_get_contents(dirname($dir) . '/work/.htaccess'), 'рабочая папка закрыта от веба');
    });
});

test('установка: откат при ошибке после замены файлов', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        $calls = 0;
        assertThrows(UpdateException::class, function () use ($dir, &$calls): void {
            installZip(makeZip('0.2.0'), $dir, '0.2.0', '0.1.0', function () use (&$calls): void {
                if (++$calls === 1) {
                    throw new RuntimeException('сбой копирования страниц админки');
                }
            });
        }, 'восстановлена прежняя версия');
        assertContains("'0.1.0'", (string) file_get_contents($dir . '/install/version.php'));
        assertTrue(is_file($dir . '/lib/OrgBox/SiteOps/Old.php'), 'старый файл должен вернуться');
        assertTrue(!file_exists($dir . '/lib/OrgBox/SiteOps/A.php'), 'новый файл должен исчезнуть');
        assertSame(2, $calls, 'хук вызывается и после отката');
    });
});

test('установка: путь с .. отклоняется, ничего не записывается', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0', ['orgbox.siteops/../evil.php' => '<?php']), $dir, '0.2.0'), 'Небезопасный путь');
        assertTrue(!file_exists(dirname($dir) . '/evil.php'));
        assertContains("'0.1.0'", (string) file_get_contents($dir . '/install/version.php'));
    });
});

test('установка: файлы вне папки orgbox.siteops отклоняются', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0', ['bitrix/php_interface/init.php' => '<?php']), $dir, '0.2.0'), 'Небезопасный путь');
        assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0', ['/etc/passwd' => 'x']), $dir, '0.2.0'), 'Небезопасный путь');
    });
});

test('установка: неразрешённые расширения отклоняются', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        foreach (['shell.phtml', 'shell.php5', 'tool.exe', 'x.sh', 'x.phar'] as $name) {
            assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0', ['orgbox.siteops/lib/' . $name => 'x']), $dir, '0.2.0'), 'Недопустимый тип');
        }
    });
});

test('установка: символы пути вне белого списка и обратная косая черта отклоняются', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0', ['orgbox.siteops/lib/a b.php' => 'x']), $dir, '0.2.0'), 'Небезопасный путь');
        assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0', ['orgbox.siteops\\lib\\x.php' => 'x']), $dir, '0.2.0'), 'Небезопасный путь');
    });
});

test('установка: версия в архиве должна совпадать с версией релиза', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, fn () => assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0'), $dir, '0.3.0'), 'не совпадает'));
});

test('установка: понижение и та же версия запрещены', function (): void {
    $dir = makeModuleDir('0.5.0');
    withCleanup($dir, function () use ($dir): void {
        assertThrows(UpdateException::class, fn () => installZip(makeZip('0.4.0'), $dir, '0.4.0', '0.5.0'), 'не новее');
        assertThrows(UpdateException::class, fn () => installZip(makeZip('0.5.0'), $dir, '0.5.0', '0.5.0'), 'не новее');
    });
});

test('установка: архив без обязательных файлов отклоняется', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, fn () => assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0', [], ['orgbox.siteops/include.php']), $dir, '0.2.0'), 'обязательного'));
});

test('установка: не zip', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, fn () => assertThrows(UpdateException::class, fn () => installZip('это не архив', $dir, '0.2.0'), 'повреждён'));
});

test('установка: слишком большой файл отклоняется', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, fn () => assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0', ['orgbox.siteops/lib/big.php' => str_repeat('a', 5242881)]), $dir, '0.2.0'), 'слишком большой'));
});

test('установка: параллельный запуск блокируется', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        $work = dirname($dir) . '/work';
        mkdir($work, 0755, true);
        $lock = fopen($work . '/.lock', 'c');
        flock($lock, LOCK_EX);
        assertThrows(UpdateException::class, fn () => installZip(makeZip('0.2.0'), $dir, '0.2.0'), 'уже выполняется');
        flock($lock, LOCK_UN);
    });
});

test('установка: хранятся только 3 последние резервные копии', function (): void {
    $dir = makeModuleDir('0.0.1');
    withCleanup($dir, function () use ($dir): void {
        $current = '0.0.1';
        foreach (['0.0.2', '0.0.3', '0.0.4', '0.0.5', '0.0.6'] as $version) {
            installZip(makeZip($version), $dir, $version, $current);
            $current = $version;
            usleep(1100000); // метка резервной копии включает секунды
        }
        assertSame(3, count(glob(dirname($dir) . '/work/backup-*', GLOB_ONLYDIR)));
    });
});

// ---- Updater: GitHub Releases -----------------------------------------------------------------

/** @return array{0:FakeHttp,1:string,2:string} HTTP-заглушка, публичный ключ, архив */
function releaseFixture(string $version, bool $private = false, bool $withSig = true): array
{
    [$public, $secret] = keypair();
    $zip = makeZip($version);
    $name = "orgbox.siteops-$version.zip";
    $asset = static fn (string $n, string $id): array => ['name' => $n, 'url' => "https://api.github.com/repos/o/r/releases/assets/$id", 'browser_download_url' => "https://github.com/o/r/releases/download/v$version/$n"];
    $assets = [$asset($name, '1'), $asset("$name.sha256", '2')];
    $responses = [
        'https://api.github.com/repos/o/r/releases/latest' => json_encode(['tag_name' => "v$version", 'body' => 'Что нового', 'assets' => $withSig ? array_merge($assets, [$asset("$name.sig", '3')]) : $assets]),
    ];
    foreach ([[$name, $zip, '1'], ["$name.sha256", hash('sha256', $zip) . "  $name\n", '2'], ["$name.sig", base64_encode(sodium_crypto_sign_detached($zip, $secret)), '3']] as [$n, $content, $id]) {
        $responses[$private ? "https://api.github.com/repos/o/r/releases/assets/$id" : "https://github.com/o/r/releases/download/v$version/$n"] = $content;
    }

    return [new FakeHttp($responses), $public, $zip];
}

function makeUpdater(FakeHttp $http, string $public, string $moduleDir, string $current, string $token = '', bool $allowUnsigned = false): Updater
{
    return new Updater($http, new ReleaseVerifier($public, $allowUnsigned), new ZipInstaller(PackageProfile::module()), PackageProfile::module(), 'o/r', $token, $moduleDir, dirname($moduleDir) . '/work', $current);
}

test('Updater: находит новую версию и ничего не делает, если версия последняя', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        [$http, $public] = releaseFixture('0.2.0');
        $release = makeUpdater($http, $public, $dir, '0.1.0')->checkLatest();
        assertSame('0.2.0', $release->version);
        assertSame('Что нового', $release->notes);
        assertSame(null, makeUpdater($http, $public, $dir, '0.2.0')->checkLatest());
        assertSame(null, makeUpdater($http, $public, $dir, '0.3.0')->checkLatest());
    });
});

test('Updater: полный цикл — загрузка, проверка подписи, установка', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        [$http, $public] = releaseFixture('0.2.0');
        $updater = makeUpdater($http, $public, $dir, '0.1.0');
        assertSame('signed', $updater->install($updater->checkLatest()));
        assertContains("'0.2.0'", (string) file_get_contents($dir . '/install/version.php'));
        assertSame([], glob(dirname($dir) . '/work/download-*') ?: [], 'скачанный архив удаляется');
    });
});

test('Updater: неверный ключ — установка отменена, модуль не тронут', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        [$http] = releaseFixture('0.2.0');
        [$otherPublic] = keypair();
        $updater = makeUpdater($http, $otherPublic, $dir, '0.1.0');
        assertThrows(UpdateException::class, fn () => $updater->install($updater->checkLatest()), 'Подпись');
        assertContains("'0.1.0'", (string) file_get_contents($dir . '/install/version.php'));
    });
});

test('Updater: релиз без подписи при настроенном ключе отклоняется', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        [$http, $public] = releaseFixture('0.2.0', false, false);
        $updater = makeUpdater($http, $public, $dir, '0.1.0');
        assertThrows(UpdateException::class, fn () => $updater->install($updater->checkLatest()), 'нет подписи');
    });
});

test('Updater: приватный репозиторий — запросы к API с токеном и Accept: octet-stream', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        [$http, $public] = releaseFixture('0.2.0', true);
        $updater = makeUpdater($http, $public, $dir, '0.1.0', 'ghp_secret');
        $updater->install($updater->checkLatest());
        foreach ($http->requests as $request) {
            assertSame('Bearer ghp_secret', $request['headers']['Authorization']);
        }
        assertSame('application/octet-stream', $http->requests[1]['headers']['Accept']);
        assertContains("'0.2.0'", (string) file_get_contents($dir . '/install/version.php'));
    });
});

test('Updater: публичный репозиторий — токен не отправляется', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        [$http, $public] = releaseFixture('0.2.0');
        $updater = makeUpdater($http, $public, $dir, '0.1.0');
        $updater->install($updater->checkLatest());
        foreach ($http->requests as $request) {
            assertTrue(!isset($request['headers']['Authorization']));
        }
    });
});

test('Updater: тег, не похожий на версию, и релиз без архива отклоняются', function (): void {
    $dir = makeModuleDir('0.1.0');
    withCleanup($dir, function () use ($dir): void {
        $http = new FakeHttp(['https://api.github.com/repos/o/r/releases/latest' => json_encode(['tag_name' => 'nightly', 'assets' => []])]);
        assertThrows(UpdateException::class, fn () => makeUpdater($http, '', $dir, '0.1.0')->checkLatest(), 'не похож на версию');
        $http = new FakeHttp(['https://api.github.com/repos/o/r/releases/latest' => json_encode(['tag_name' => 'v0.2.0', 'assets' => []])]);
        assertThrows(UpdateException::class, fn () => makeUpdater($http, '', $dir, '0.1.0')->checkLatest(), 'нет архива');
    });
});

test('Updater: некорректное имя репозитория отклоняется', function (): void {
    foreach (['evil', 'a/b/c', '../x', 'a/b;rm', ''] as $repo) {
        assertThrows(UpdateException::class, fn () => new Updater(new FakeHttp([]), new ReleaseVerifier('', true), new ZipInstaller(PackageProfile::module()), PackageProfile::module(), $repo, '', '/x', '/y', '0.1.0'));
    }
});

test('Updater: HTTP-клиент не принимает не-HTTPS адреса', function (): void {
    assertThrows(UpdateException::class, fn () => (new OrgBox\SiteOps\Update\CurlHttpClient())->get('http://example.com/x'), 'HTTPS');
    assertThrows(UpdateException::class, fn () => (new OrgBox\SiteOps\Update\CurlHttpClient())->get('file:///etc/passwd'), 'HTTPS');
});

test('Релиз: собранный tools/build-release.php устанавливается Updater-ом', function (): void {
    $out = tempDir();
    try {
        $version = null;
        $arModuleVersion = [];
        require __DIR__ . '/../modules/orgbox.siteops/install/version.php';
        $version = $arModuleVersion['VERSION'];
        exec('php ' . escapeshellarg(__DIR__ . '/../tools/build-release.php') . ' ' . escapeshellarg($out) . ' 2>&1', $lines, $code);
        assertSame(0, $code, implode("\n", $lines));
        $zip = (string) file_get_contents($out . "/orgbox.siteops-$version.zip");
        $sha = (string) file_get_contents($out . "/orgbox.siteops-$version.zip.sha256");
        assertContains(hash('sha256', $zip), $sha);

        $dir = makeModuleDir('0.0.1');
        withCleanup($dir, function () use ($dir, $zip, $version): void {
            installZip($zip, $dir, $version, '0.0.1');
            assertTrue(is_file($dir . '/lib/OrgBox/SiteOps/Update/Updater.php'));
            assertTrue(is_file($dir . '/install/tools/orgbox_siteops/mcp.php'));
        });
    } finally {
        removeDir($out);
    }
});
