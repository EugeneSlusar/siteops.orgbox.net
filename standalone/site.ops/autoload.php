<?php

declare(strict_types=1);

// Автозагрузчик классов OrgBox\SiteOps из папки lib/ (без регистрации модуля в Битриксе).
spl_autoload_register(static function (string $class): void {
    $prefix = 'OrgBox\\SiteOps\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/lib/OrgBox/SiteOps/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
