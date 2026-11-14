<?php

declare(strict_types=1);

/**
 * Подписывает архив релиза: создаёт <архив>.sig (подпись Ed25519, base64).
 * Секретный ключ берётся из переменной окружения SITEOPS_SIGNING_KEY.
 * Использование: SITEOPS_SIGNING_KEY=... php tools/sign-release.php dist/orgbox.siteops-0.2.0.zip
 */
$zip = $argv[1] ?? '';
$key = base64_decode((string) getenv('SITEOPS_SIGNING_KEY'), true);
if (!is_file($zip)) {
    fwrite(STDERR, "Укажите путь к архиву.\n");
    exit(1);
}
if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "SITEOPS_SIGNING_KEY не задан или имеет неверный формат.\n");
    exit(2);
}
$signature = sodium_crypto_sign_detached((string) file_get_contents($zip), $key);
file_put_contents($zip . '.sig', base64_encode($signature) . "\n");
echo "Подпись: $zip.sig\n";
