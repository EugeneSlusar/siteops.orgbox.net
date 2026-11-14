<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

/** Загрузка только по HTTPS, с ограничением размера и времени. */
final class CurlHttpClient implements HttpClient
{
    public function get(string $url, array $headers = [], int $maxBytes = 26214400): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new UpdateException('Разрешены только HTTPS-адреса.');
        }
        if (!function_exists('curl_init')) {
            throw new UpdateException('На сервере не включено расширение curl.');
        }

        $lines = ['User-Agent: orgbox-siteops-updater'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($r, $total, $downloaded): int => $downloaded > $maxBytes ? 1 : 0,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new UpdateException('Ошибка загрузки: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new UpdateException('Сервер обновлений ответил кодом ' . $status . '.');
        }

        return (string) $body;
    }
}
