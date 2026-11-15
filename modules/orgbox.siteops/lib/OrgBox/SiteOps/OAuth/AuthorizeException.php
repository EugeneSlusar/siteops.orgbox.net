<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\OAuth;

use RuntimeException;

/**
 * Ошибка запроса авторизации. Если redirectUri задан, клиент и адрес возврата проверены и ошибку можно вернуть
 * редиректом; иначе её нужно показать пользователю на странице (редирект на непроверенный адрес опасен).
 */
final class AuthorizeException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($description);
    }
}
