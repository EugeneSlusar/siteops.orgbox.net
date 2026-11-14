<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Mcp;

use RuntimeException;

/** Ошибка выполнения инструмента: показывается ИИ как результат с isError, а не как сбой протокола. */
final class ToolException extends RuntimeException
{
}
