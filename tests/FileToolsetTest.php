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
    file_put_contents($root . '/help/warranty/index.php', "<?php\n// warranty\nreturn true;\n");
    file_put_contents($root . '/catalog/index.php', implode("\n", array_map(static fn (int $i): string => 'line ' . $i, range(1, 80))));
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
        assertSame('file.rename', $audit->entries[1][0]);
        assertSame('denied', $audit->entries[1][1]['result']);
    } finally {
        removeFileToolsetFixture($root);
    }
});
