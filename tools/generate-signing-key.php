<?php

declare(strict_types=1);

/**
 * Создаёт пару ключей Ed25519 для подписи релизов. Запускайте на своём компьютере.
 *  - ОТКРЫТЫЙ ключ вставьте в настройки модуля (поле «Открытый ключ подписи») или в update_public_key.
 *  - СЕКРЕТНЫЙ ключ сохраните только в секретах GitHub-репозитория (SITEOPS_SIGNING_KEY). Нигде больше не храните.
 */
if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "Нужно расширение sodium.\n");
    exit(1);
}
$pair = sodium_crypto_sign_keypair();
echo "Открытый ключ (публикуется):\n" . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n\n";
echo "Секретный ключ (ТОЛЬКО в секреты GitHub SITEOPS_SIGNING_KEY, никому не показывать):\n" . base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n";
