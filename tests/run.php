<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$failed = 0;
foreach ($GLOBALS['__tests'] as $name => $body) {
    try {
        $body();
        fwrite(STDOUT, "  ok   $name\n");
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDOUT, "  FAIL $name\n       " . get_class($exception) . ': ' . $exception->getMessage() . "\n       at " . basename($exception->getFile()) . ':' . $exception->getLine() . "\n");
    }
}

fwrite(STDOUT, "\n" . count($GLOBALS['__tests']) . ' тестов, ' . $failed . " с ошибками\n");
exit($failed === 0 ? 0 : 1);
