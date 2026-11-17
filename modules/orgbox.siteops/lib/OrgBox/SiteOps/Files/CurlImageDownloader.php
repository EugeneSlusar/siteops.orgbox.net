<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Files;

use OrgBox\SiteOps\Mcp\ToolException;

/**
 * Скачивание картинки по HTTPS с защитой от SSRF: только порт 443, только публичные адреса,
 * адрес проверяется и «закрепляется» (CURLOPT_RESOLVE), переадресации обрабатываются вручную с той же проверкой.
 */
final class CurlImageDownloader implements ImageDownloader
{
    private const MAX_REDIRECTS = 3;

    public function download(string $url, int $maxBytes): string
    {
        if (!function_exists('curl_init')) {
            throw new ToolException('На сервере не включено расширение curl.');
        }
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $ip] = $this->check($url);
            $location = null;
            $body = $this->fetch($url, $host, $ip, $maxBytes, $location);
            if ($location === null) {
                return $body;
            }
            $url = $this->absolute($url, $location);
        }

        throw new ToolException('Слишком много переадресаций при загрузке картинки.');
    }

    /** @return array{0:string,1:string} [хост, публичный IP] */
    private function check(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ToolException('Картинку можно загрузить только по HTTPS-адресу без логина и пароля.');
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new ToolException('Разрешён только порт 443.');
        }
        $host = strtolower((string) $parts['host']);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new ToolException('Не удалось определить адрес сервера ' . $host . '.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ToolException('Адрес ' . $host . ' ведёт во внутреннюю сеть: загрузка запрещена.');
            }
        }

        return [$host, $ips[0]];
    }

    private function fetch(string $url, string $host, string $ip, int $maxBytes, ?string &$location): string
    {
        $headers = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $ip],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['User-Agent: orgbox-siteops', 'Accept: image/*'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($r, $total, $downloaded): int => $downloaded > $maxBytes ? 1 : 0,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                $headers .= $line;

                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new ToolException('Не удалось скачать картинку: ' . ($error !== '' ? $error : 'ошибка сети') . '.');
        }
        if ($status >= 300 && $status < 400 && preg_match('/^location:\s*(\S+)/mi', $headers, $m)) {
            $location = $m[1];

            return '';
        }
        if ($status < 200 || $status >= 300) {
            throw new ToolException('Сервер картинки ответил кодом ' . $status . '.');
        }

        return (string) $body;
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);

        return 'https://' . $parts['host'] . '/' . ltrim($location, '/');
    }
}
