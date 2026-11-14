<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Auth;

use RuntimeException;

final class AuthException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 401)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
