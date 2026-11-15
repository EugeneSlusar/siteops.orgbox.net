<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\OAuth;

use RuntimeException;

/** Ошибка OAuth в формате RFC 6749: код (error), описание и HTTP-статус. */
final class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($description);
    }
}
