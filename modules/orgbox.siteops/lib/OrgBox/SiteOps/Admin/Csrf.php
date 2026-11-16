<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Admin;

/**
 * Защита форм от подделки запросов (CSRF), не зависящая от сессии PHP.
 * Схема «двойной отправки»: случайное значение лежит в cookie (SameSite=Strict, только на пути /site.ops/),
 * а в форму кладётся его HMAC с секретом сайта. Чужая страница не может ни прочитать cookie, ни вычислить HMAC.
 * Штатная проверка Битрикса (check_bitrix_sessid) остаётся запасным вариантом: достаточно любой из двух.
 */
final class Csrf
{
    public const COOKIE = 'siteops_csrf';
    public const FIELD = 'siteops_csrf';

    public function __construct(private string $secret)
    {
    }

    /** @return array{cookie:string,field:string} значение cookie и значение скрытого поля формы */
    public function issue(?string $existingCookie): array
    {
        $cookie = self::validCookie($existingCookie) ? (string) $existingCookie : bin2hex(random_bytes(16));

        return ['cookie' => $cookie, 'field' => $this->sign($cookie)];
    }

    public function verify(mixed $cookie, mixed $field): bool
    {
        if (!is_string($cookie) || !self::validCookie($cookie) || !is_string($field)) {
            return false;
        }

        return hash_equals($this->sign($cookie), $field);
    }

    public static function validCookie(?string $value): bool
    {
        return $value !== null && preg_match('/^[a-f0-9]{32}$/', $value) === 1;
    }

    public static function sendCookie(string $value, bool $secure): void
    {
        @setcookie(self::COOKIE, $value, [
            'expires' => 0,
            'path' => '/site.ops/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function sign(string $cookie): string
    {
        return hash_hmac('sha256', 'siteops-csrf|' . $cookie, $this->secret);
    }
}
