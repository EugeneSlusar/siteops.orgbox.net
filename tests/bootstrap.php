<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $no, $file, $line);
});

spl_autoload_register(static function (string $class): void {
    foreach (['OrgBox\\SiteOps\\' => __DIR__ . '/../modules/orgbox.siteops/lib/OrgBox/SiteOps/', 'Tests\\Support\\' => __DIR__ . '/Support/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

/** @var array<string,callable> $GLOBALS['__tests'] */
$GLOBALS['__tests'] = [];

function test(string $name, callable $body): void
{
    $GLOBALS['__tests'][$name] = $body;
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(($message !== '' ? $message . ': ' : '') . 'ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
}

function assertTrue(mixed $value, string $message = ''): void
{
    assertSame(true, $value, $message);
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionError(($message !== '' ? $message . ': ' : '') . 'нет подстроки «' . $needle . '» в «' . $haystack . '»');
    }
}

function assertThrows(string $class, callable $body, ?string $messagePart = null): void
{
    try {
        $body();
    } catch (Throwable $exception) {
        if (!$exception instanceof $class) {
            throw new AssertionError('ожидалось ' . $class . ', получено ' . get_class($exception) . ': ' . $exception->getMessage());
        }
        if ($messagePart !== null) {
            assertContains($messagePart, $exception->getMessage());
        }

        return;
    }
    throw new AssertionError('исключение ' . $class . ' не выброшено');
}

function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/siteops-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    return $dir;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
