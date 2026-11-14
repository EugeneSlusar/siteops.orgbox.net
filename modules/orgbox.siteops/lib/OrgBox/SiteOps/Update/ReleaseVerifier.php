<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

/** Проверка подлинности скачанного архива: SHA-256 и подпись Ed25519. */
final class ReleaseVerifier
{
    public function __construct(private string $publicKeyBase64, private bool $allowUnsigned)
    {
    }

    public function verify(string $zip, string $shaFileContent, ?string $signatureBase64): string
    {
        $expected = $this->parseSha256($shaFileContent);
        if (!hash_equals($expected, hash('sha256', $zip))) {
            throw new UpdateException('Контрольная сумма архива не совпала. Установка отменена.');
        }

        if ($this->publicKeyBase64 !== '') {
            if ($signatureBase64 === null || trim($signatureBase64) === '') {
                throw new UpdateException('У релиза нет подписи, а открытый ключ настроен. Установка отменена.');
            }
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                throw new UpdateException('На сервере нет расширения sodium: подпись проверить нельзя.');
            }
            $publicKey = base64_decode($this->publicKeyBase64, true);
            $signature = base64_decode(trim($signatureBase64), true);
            if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                throw new UpdateException('Ключ или подпись имеют неверный формат.');
            }
            if (!sodium_crypto_sign_verify_detached($signature, $zip, $publicKey)) {
                throw new UpdateException('Подпись релиза недействительна. Установка отменена.');
            }

            return 'signed';
        }

        if (!$this->allowUnsigned) {
            throw new UpdateException('Не задан открытый ключ для проверки подписи. Задайте ключ или явно разрешите обновления без подписи (только SHA-256).');
        }

        return 'checksum-only';
    }

    private function parseSha256(string $content): string
    {
        if (!preg_match('/\b([a-f0-9]{64})\b/i', $content, $match)) {
            throw new UpdateException('Файл контрольной суммы повреждён.');
        }

        return strtolower($match[1]);
    }
}
