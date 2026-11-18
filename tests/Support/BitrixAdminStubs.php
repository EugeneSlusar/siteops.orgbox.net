<?php

declare(strict_types=1);

// Минимальные заглушки Битрикса для прогона страницы настроек в тестах (без базы и без настоящего администратора).

namespace Bitrix\Main {
    if (!class_exists(Application::class, false)) {
        final class Application
        {
            public static function getConnection(): DB\Connection
            {
                return new DB\Connection();
            }
        }
    }
}

namespace Bitrix\Main\DB {
    if (!class_exists(Connection::class, false)) {
        final class SqlHelper
        {
            public function forSql(string $v): string
            {
                return addslashes($v);
            }
        }

        final class Result
        {
            public function fetch(): array|false
            {
                return false;
            }
        }

        final class Connection
        {
            public function queryExecute(string $sql): void
            {
            }

            public function query(string $sql): Result
            {
                return new Result();
            }

            public function getAffectedRowsCount(): int
            {
                return 0;
            }

            public function getSqlHelper(): SqlHelper
            {
                return new SqlHelper();
            }
        }
    }
}

namespace {
    $GLOBALS['__sessid_ok'] = true;

    if (!function_exists('check_bitrix_sessid')) {
        function check_bitrix_sessid(): bool
        {
            return (bool) $GLOBALS['__sessid_ok'];
        }

        function bitrix_sessid_post(): string
        {
            return '<input type="hidden" name="sessid" value="test">';
        }

        function htmlspecialcharsbx(mixed $value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES);
        }

        function CopyDirFiles(string $from, string $to, bool $a = true, bool $b = true): void
        {
        }

        final class CEventLog
        {
            /** @var list<array<string,mixed>> */
            public static array $entries = [];

            /** @param array<string,mixed> $fields */
            public static function Add(array $fields): void
            {
                self::$entries[] = $fields;
            }
        }

        final class CAdminMessage
        {
            public static function ShowMessage(array|string $message): void
            {
                echo '[MSG ' . (is_array($message) ? $message['TYPE'] . ': ' . $message['MESSAGE'] : $message) . "]\n";
            }
        }

        final class TestApplication
        {
            public function GetCurPageParam(string $a, array $b, bool $c): string
            {
                return '/site.ops/admin/';
            }

            public function SetTitle(string $title): void
            {
            }

            public function AddHeadString(string $string, bool $unique = false, bool $fileBody = false): void
            {
            }
        }

        final class TestUser
        {
            public function GetID(): int
            {
                return 1;
            }

            public function GetLogin(): string
            {
                return 'admin';
            }

            public function IsAdmin(): bool
            {
                return true;
            }
        }
    }
}
