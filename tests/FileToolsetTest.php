<?php

declare(strict_types=1);

use OrgBox\SiteOps\Mcp\ToolException;
use OrgBox\SiteOps\Mcp\ToolRegistry;
use OrgBox\SiteOps\Tools\FileToolset;
use Tests\Support\MemoryAudit;

function fileToolsetFixture(): array
{
    $root = sys_get_temp_dir() . '/siteops-files-' . bin2hex(random_bytes(6));
    mkdir($root . '/help/warranty', 0700, true);
    mkdir($root . '/catalog', 0700, true);
    mkdir($root . '/bitrix/components/maxma/basket.bonus', 0700, true);
    mkdir($root . '/bitrix/modules/maxma.loyalty/install/components/maxma/basket.bonus', 0700, true);
    mkdir($root . '/local/modules/maxma.loyalty', 0700, true);
    mkdir($root . '/bitrix/templates/store/.default', 0700, true);
    mkdir($root . '/local/templates/store/.default', 0700, true);
    file_put_contents($root . '/help/warranty/index.php', "<?php\n// warranty\nreturn true;\n");
    file_put_contents($root . '/catalog/index.php', implode("\n", array_map(static fn (int $i): string => 'line ' . $i, range(1, 80))));
    file_put_contents($root . '/bitrix/components/maxma/basket.bonus/class.php', "<?php\n// component\n");
    file_put_contents($root . '/bitrix/modules/maxma.loyalty/install/components/maxma/basket.bonus/class.php', "<?php\n// module\n");
    file_put_contents($root . '/local/modules/maxma.loyalty/include.php', "<?php\n// local module\n");
    file_put_contents($root . '/bitrix/templates/store/.default/template.php', "<?php\n// bitrix template\n");
    file_put_contents($root . '/local/templates/store/.default/template.php', "<?php\n// local template\n");
    $audit = new MemoryAudit();
    $registry = new ToolRegistry();
    (new FileToolset($root, $audit))->registerAll($registry);

    return [$root, $registry, $audit];
}

function removeFileToolsetFixture(string $root): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

test('файлы: file_read возвращает нормальный путь, строки и признак обрезания', function (): void {
    [$root, $registry, $audit] = fileToolsetFixture();
    try {
        $read = $registry->find('file_read', false);
        assertTrue($read !== null);
        assertSame(['path' => 'help/warranty/index.php', 'content' => "1: <?php\n2: // warranty", 'truncated' => true], $read->call(['path' => 'help/warranty/index.php', 'limit' => 2]));
        assertContains('20: line 20', $read->call(['path' => 'catalog/index.php', 'offset' => 20, 'limit' => 50])['content']);
        assertSame('file.read', $audit->entries[0][0]);
        assertSame('success', $audit->entries[0][1]['result']);
        assertTrue(!isset($audit->entries[0][1]['content']));
    } finally {
        removeFileToolsetFixture($root);
    }
});

test('файлы: запрещённые пути, скрытые файлы и служебные области не читаются', function (): void {
    [$root, $registry, $audit] = fileToolsetFixture();
    try {
        $read = $registry->find('file_read', false);
        foreach (['../etc/passwd', '/var/www/site/.env', '.htaccess', 'bitrix/.settings.php', 'local/php_interface/init.php', 'upload/private/customer-list.csv'] as $path) {
            try {
                $read->call(['path' => $path]);
                throw new RuntimeException('Ожидался отказ: ' . $path);
            } catch (ToolException) {
            }
        }
        assertSame(6, count($audit->entries));
        assertSame('denied', $audit->entries[0][1]['result']);
    } finally {
        removeFileToolsetFixture($root);
    }
});

test('файлы: file_read читает обычные текстовые файлы в bitrix и local', function (): void {
    [$root, $registry] = fileToolsetFixture();
    try {
        $read = $registry->find('file_read', false);
        assertContains('component', $read->call(['path' => 'bitrix/components/maxma/basket.bonus/class.php'])['content']);
        assertContains('module', $read->call(['path' => 'bitrix/modules/maxma.loyalty/install/components/maxma/basket.bonus/class.php'])['content']);
        assertContains('local module', $read->call(['path' => 'local/modules/maxma.loyalty/include.php'])['content']);
        assertContains('bitrix template', $read->call(['path' => 'bitrix/templates/store/.default/template.php'])['content']);
        assertContains('local template', $read->call(['path' => 'local/templates/store/.default/template.php'])['content']);
        foreach (['bitrix/.settings.php', 'local/php_interface/dbconn.php'] as $path) {
            try {
                $read->call(['path' => $path]);
                throw new RuntimeException('Ожидался отказ: ' . $path);
            } catch (ToolException) {
            }
        }
    } finally {
        removeFileToolsetFixture($root);
    }
});

test('файлы: file_read допускает только цель симлинка public-root/bitrix', function (): void {
    $root = sys_get_temp_dir() . '/siteops-files-root-' . bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir() . '/siteops-files-bitrix-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    mkdir($outside . '/components/maxma', 0700, true);
    mkdir($outside . '/external', 0700, true);
    file_put_contents($outside . '/components/maxma/class.php', "<?php\n// shared bitrix\n");
    file_put_contents($outside . '/external/class.php', "<?php\n// nested shared bitrix\n");
    symlink($outside, $root . '/bitrix');
    $audit = new MemoryAudit();
    $registry = new ToolRegistry();
    (new FileToolset($root, $audit))->registerAll($registry);
    try {
        $read = $registry->find('file_read', false);
        assertContains('shared bitrix', $read->call(['path' => 'bitrix/components/maxma/class.php'])['content']);
        symlink($outside . '/external', $outside . '/components/maxma/external');
        assertContains('nested shared bitrix', $read->call(['path' => 'bitrix/components/maxma/external/class.php'])['content']);
    } finally {
        unlink($root . '/bitrix');
        rmdir($root);
        unlink($outside . '/components/maxma/external');
        unlink($outside . '/components/maxma/class.php');
        rmdir($outside . '/components/maxma');
        rmdir($outside . '/components');
        unlink($outside . '/external/class.php');
        rmdir($outside . '/external');
        rmdir($outside);
    }
});

test('файлы: file_rename перемещает один файл без перезаписи и проверяет результат', function (): void {
    [$root, $registry, $audit] = fileToolsetFixture();
    try {
        $rename = $registry->find('file_rename', true);
        assertTrue($rename !== null);
        $result = $rename->call(['from' => 'help/warranty/index.php', 'to' => 'help/warranty/index.php.bak']);
        assertSame('help/warranty/index.php', $result['from']);
        assertSame('help/warranty/index.php.bak', $result['to']);
        assertTrue($result['verified']);
        assertTrue(!file_exists($root . '/help/warranty/index.php') && file_exists($root . '/help/warranty/index.php.bak'));
        try {
            $rename->call(['from' => 'help/warranty/index.php.bak', 'to' => '.backup/index.php']);
            throw new RuntimeException('Ожидался отказ скрытой цели.');
        } catch (ToolException) {
        }
        try {
            $rename->call(['from' => 'bitrix/components/maxma/basket.bonus/class.php', 'to' => 'bitrix/components/maxma/basket.bonus/class.php.bak']);
            throw new RuntimeException('Ожидался отказ пути bitrix.');
        } catch (ToolException) {
        }
        try {
            $rename->call(['from' => 'local/modules/maxma.loyalty/include.php', 'to' => 'local/modules/maxma.loyalty/include.php.bak']);
            throw new RuntimeException('Ожидался отказ пути local.');
        } catch (ToolException) {
        }
        assertSame('file.rename', $audit->entries[1][0]);
        assertSame('denied', $audit->entries[1][1]['result']);
    } finally {
        removeFileToolsetFixture($root);
    }
});
